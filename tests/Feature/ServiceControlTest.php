<?php

namespace Tests\Feature;

use App\Filament\Widgets\ContainerMetrics;
use App\Filament\Widgets\ServiceStats;
use App\Services\SystemMonitor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceControlTest extends TestCase
{
    private const ID = 'ad035c5f2ae5';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
    }

    public static function operations(): array
    {
        return [
            'start' => ['start', 'exited', 'running'],
            'stop' => ['stop', 'running', 'exited'],
            'restart running' => ['restart', 'running', 'running'],
            'restart stopped' => ['restart', 'exited', 'running'],
        ];
    }

    #[DataProvider('operations')]
    public function test_commands_require_confirmation_and_refresh_all_monitor_caches(string $operation, string $initial, string $after): void
    {
        $status = $initial;
        Http::fake([
            'monitor.test/api/stats*' => function () use (&$status) {
                return Http::response(['items' => [['id' => self::ID, 'name' => 'selected-service', 'status' => $status]]]);
            },
            'monitor.test/api/containers/'.self::ID.'/stats' => function () use (&$status) {
                return Http::response(['status' => $status]);
            },
            'monitor.test/api/containers/'.self::ID.'/'.$operation => function () use (&$status, $after) {
                $status = $after;

                return Http::response(['status' => $after]);
            },
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => function () use (&$status) {
                return Http::response(['containers_running' => $status === 'running' ? 1 : 0]);
            },
            'monitor.test/api/images*' => Http::response(['total_items' => 1]),
        ]);
        $monitor = app(SystemMonitor::class);
        $monitor->snapshot();
        $monitor->fetch('/api/stats', ['page' => 2, 'page_size' => 10, 'all' => 'true']);
        Cache::put('unrelated-project-cache', 'preserved', 60);
        $counts = Livewire::test(ServiceStats::class);

        $widget = Livewire::test(ContainerMetrics::class)->assertDontSeeHtml('@js(');
        $widget->mountTableAction($operation, self::ID)
            ->assertSet('mountedTableActionRecord', self::ID)
            ->assertSee('selected-service')
            ->assertTableActionMounted($operation);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');

        $widget->callMountedTableAction()->assertSet('mountedTableActions', [])
            ->assertDispatched('monitor-services-updated');
        Notification::assertNotified('عملیات با موفقیت انجام شد');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://monitor.test/api/containers/'.self::ID.'/'.$operation);
        $this->assertSame($after, $monitor->fetch('/api/stats', ['page' => 2, 'page_size' => 10, 'all' => 'true'])['items'][0]['status']);
        $this->assertSame($after === 'running' ? 1 : 0, $monitor->snapshot()['summary']['containers_running']);
        $this->assertSame('preserved', Cache::get('unrelated-project-cache'));
        $counts->dispatch('monitor-services-updated')->assertSee($after === 'running' ? '1' : '0');
        $widget->callMountedTableAction();
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_cancel_and_invalid_operations_never_send_commands(): void
    {
        Http::fake(['monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'name' => 'service', 'status' => 'running']]])]);
        $widget = Livewire::test(ContainerMetrics::class);
        $widget->mountTableAction('stop', self::ID)->unmountTableAction()
            ->assertSet('mountedTableActions', [])->callMountedTableAction();
        $widget->mountTableAction('restart', self::ID)->unmountTableAction()->callMountedTableAction();
        foreach ([[self::ID, 'remove'], ['../invalid', 'stop'], ['61638b47b7aa', 'stop'], [self::ID, 'start']] as [$id, $operation]) {
            $widget->call('mountTableAction', $operation, $id)->assertSet('mountedTableActions', []);
        }
        $this->assertFalse(app(SystemMonitor::class)->control('../invalid', 'stop')['ok']);
        $this->assertFalse(app(SystemMonitor::class)->control(self::ID, 'remove')['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_a_status_change_before_confirmation_prevents_a_stale_command(): void
    {
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'status' => 'running']]]),
            'monitor.test/api/containers/'.self::ID.'/stats' => Http::response(['status' => 'exited']),
        ]);
        Livewire::test(ContainerMetrics::class)->mountTableAction('stop', self::ID)
            ->callMountedTableAction()->assertSet('mountedTableActions', []);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Notification::assertNotified('عملیات ارسال نشد');
    }

    public function test_forged_action_records_cannot_send_commands(): void
    {
        Http::fake(['monitor.test/api/stats*' => Http::response(['items' => []])]);
        Livewire::test(ContainerMetrics::class)
            ->call('mountTableAction', 'stop', self::ID)
            ->callMountedTableAction();
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public static function interruptedCommands(): array
    {
        return [['stop'], ['restart']];
    }

    #[DataProvider('interruptedCommands')]
    public function test_a_command_timeout_is_not_retried_or_reported_as_success(string $operation): void
    {
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'status' => 'running']]]),
            'monitor.test/api/containers/'.self::ID.'/stats' => Http::response(['status' => 'running']),
            'monitor.test/api/containers/'.self::ID.'/'.$operation => Http::failedConnection(),
        ]);
        Livewire::test(ContainerMetrics::class)->mountTableAction($operation, self::ID)
            ->callMountedTableAction()->assertSet('mountedTableActions', [])
            ->assertDispatched('monitor-services-updated')->callMountedTableAction();
        Notification::assertNotified('عملیات تأیید نشد');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_restart_rechecks_state_before_sending_the_command(): void
    {
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'name' => 'worker', 'status' => 'running']]]),
            'monitor.test/api/containers/'.self::ID.'/stats' => Http::response(['status' => 'restarting']),
        ]);
        Livewire::test(ContainerMetrics::class)->mountTableAction('restart', self::ID)
            ->assertSee(__('monitoring.control.restart_heading'))
            ->assertSee(__('monitoring.control.restart_warning'))
            ->callMountedTableAction()->assertSet('mountedTableActions', []);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Notification::assertNotified('عملیات ارسال نشد');
    }

    public static function errors(): array
    {
        return [
            'HTTP error' => [409, ['detail' => 'cannot start']],
            'application error' => [200, ['status' => 'error']],
            'unsuccessful response' => [200, ['success' => false]],
            'invalid response' => [200, '<html>proxy login</html>'],
        ];
    }

    #[DataProvider('errors')]
    public function test_api_errors_are_not_reported_as_success(int $status, mixed $body): void
    {
        Http::fake(['monitor.test/api/containers/'.self::ID.'/start' => Http::response($body, $status)]);
        $result = app(SystemMonitor::class)->control(self::ID, 'start');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
        Http::assertSentCount(1);
    }
}
