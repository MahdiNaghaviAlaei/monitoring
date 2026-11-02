<?php

namespace Tests\Feature;

use App\Filament\Widgets\ContainerMetrics;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitoringRecoveryTest extends TestCase
{
    private const ID = 'ad035c5f2ae5';

    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test', 'system-monitor.protected_containers' => ['system_monitor']]);
        Http::preventStrayRequests();
    }

    public static function protectedOperations(): array
    {
        return [
            ['stop', 'monitoring.control.protected_title'],
            ['restart', 'monitoring.control.protected_restart_title'],
        ];
    }

    #[DataProvider('protectedOperations')]
    public function test_the_monitoring_api_is_protected_from_disruptive_commands(string $operation, string $title): void
    {
        Http::fake(['monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'name' => 'system_monitor', 'status' => 'running']]])]);
        Livewire::test(ContainerMetrics::class)->assertSee('محافظت‌شده')
            ->assertTableActionDisabled($operation, self::ID)->call('mountTableAction', $operation, self::ID)->assertSet('mountedTableActions', [])
            ->assertNotDispatched('open-modal')->callMountedTableAction();

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertTrue(ContainerMetrics::isProtectedContainer(self::ID, '/SYSTEM_MONITOR'));
        config(['system-monitor.protected_containers' => [self::ID]]);
        $this->assertTrue(ContainerMetrics::isProtectedContainer(self::ID, 'renamed-api'));
    }

    #[DataProvider('protectedOperations')]
    public function test_protection_is_checked_again_when_confirming_a_command(string $operation, string $title): void
    {
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => self::ID, 'name' => 'worker', 'status' => 'running']]]),
            'monitor.test/api/containers/'.self::ID.'/stats' => Http::response(['name' => 'system_monitor', 'status' => 'running']),
        ]);
        Livewire::test(ContainerMetrics::class)->mountTableAction($operation, self::ID)
            ->assertSet('mountedTableActionRecord', self::ID)->callMountedTableAction();
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Notification::assertNotified(__($title));
    }

    public function test_stopped_containers_can_be_started_from_inventory_when_stats_fail(): void
    {
        $status = 'exited';
        Http::fake([
            'monitor.test/api/stats*' => Http::response([], 500),
            'monitor.test/api/containers?*' => function () use (&$status) {
                return Http::response(['items' => [['id' => self::ID, 'name' => 'worker', 'status' => $status]], 'total_items' => 1, 'total_pages' => 1]);
            },
            'monitor.test/api/containers/'.self::ID.'/stats' => Http::response([], 404),
            'monitor.test/api/containers/'.self::ID.'/start' => function () use (&$status) {
                $status = 'running';

                return Http::response(['status' => 'started']);
            },
        ]);
        Livewire::test(ContainerMetrics::class)->assertSee('worker')->assertSee('متوقف')
            ->assertSee('آمار مصرف در دسترس نیست')
            ->mountTableAction('start', self::ID)->assertSet('mountedTableActionRecord', self::ID)
            ->callMountedTableAction()->assertSee('در حال اجرا');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/start'));
        Notification::assertNotified('عملیات با موفقیت انجام شد');
    }

    public function test_an_outage_preserves_names_without_old_metrics_or_enabled_commands_and_recovers(): void
    {
        $online = true;
        Http::fake([
            'monitor.test/api/stats*' => function () use (&$online) {
                return $online
                    ? Http::response(['items' => [['id' => self::ID, 'name' => 'worker', 'status' => 'running', 'cpu_percent' => 46]], 'total_items' => 1, 'total_pages' => 1])
                    : Http::failedConnection();
            },
            'monitor.test/api/containers?*' => Http::failedConnection(),
        ]);
        $widget = Livewire::test(ContainerMetrics::class)->assertSee('46.0 %');
        $online = false;
        $widget->call('refreshServices')->assertSee('worker')->assertSee(self::ID)
            ->assertSee('آخرین دریافت موفق')->assertDontSee('46.0 %')
            ->mountTableAction('stop', self::ID)->assertSet('mountedTableActions', [])
            ->mountTableAction('start', self::ID)->assertSet('mountedTableActions', []);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        Livewire::test(ContainerMetrics::class)->assertSee('worker')->assertSee('آخرین دریافت موفق');
        $online = true;
        $widget->call('refreshServices')->assertSee('46.0 %')->assertDontSee('آخرین دریافت موفق');
    }

    public function test_a_first_visit_during_an_outage_shows_host_recovery_instructions(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Livewire::test(ContainerMetrics::class)->assertSee('docker ps -a')
            ->assertSee('docker start CONTAINER_NAME')->assertSee('بررسی مجدد اتصال')
            ->assertSee('اطلاعات سرویس‌ها در دسترس نیست');
        Http::assertSentCount(2);
    }
}
