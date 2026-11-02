<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStats;
use App\Services\SystemMonitor;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SystemMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
    }

    public function test_it_renders_live_metrics_and_reuses_a_recent_snapshot(): void
    {
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => Http::response($this->summary()),
        ]);

        Livewire::test(DashboardStats::class)
            ->assertSee('11.8٪')
            ->assertSee('28.4٪')
            ->assertSee('10.3٪')
            ->assertSee('02:05:45')
            ->assertSee('usrv01')
            ->assertSeeHtml('wire:poll.10s');

        app(SystemMonitor::class)->snapshot();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://monitor.test/health');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'http://monitor.test/api/summary');
    }

    public function test_connection_failures_render_unavailable_without_crashing(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        Livewire::test(DashboardStats::class)
            ->assertSee('در دسترس نیست')
            ->assertDontSee('سالم')
            ->assertDontSee('0.0٪');
    }

    public function test_health_is_independent_from_metrics_and_zero_is_a_valid_value(): void
    {
        $summary = $this->summary();
        $summary['host']['cpu_percent'] = 0;
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'error'], 503),
            'monitor.test/api/summary' => Http::response($summary),
        ]);

        Livewire::test(DashboardStats::class)
            ->assertSee('0.0٪')
            ->assertSee('28.4٪');
    }

    public function test_invalid_json_and_missing_metrics_are_not_displayed_as_zero(): void
    {
        Http::fake([
            'monitor.test/health' => Http::response('<html>Bad gateway</html>'),
            'monitor.test/api/summary' => Http::response(['host' => ['cpu_percent' => 900]]),
        ]);

        Livewire::test(DashboardStats::class)
            ->assertSee('در دسترس نیست')
            ->assertDontSee('900.0٪')
            ->assertDontSee('0.0٪');
    }

    private function summary(): array
    {
        return [
            'host' => [
                'hostname' => 'usrv01',
                'uptime' => '02:05:45',
                'cpu_percent' => 11.83,
                'mem_used_mb' => 1098.16,
                'mem_total_mb' => 3868.02,
                'mem_percent' => 28.39,
                'disk_used_gb' => 9.43,
                'disk_total_gb' => 96.88,
                'disk_percent' => 10.26,
            ],
            'containers_total' => 2,
            'containers_running' => 2,
            'containers_stopped' => 0,
        ];
    }
}
