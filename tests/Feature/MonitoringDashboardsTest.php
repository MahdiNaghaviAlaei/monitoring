<?php

namespace Tests\Feature;

use App\Filament\Pages\ServicesDashboard;
use App\Filament\Pages\SystemDashboard;
use App\Filament\Widgets\ContainerHistoryChart;
use App\Filament\Widgets\ContainerLogs;
use App\Filament\Widgets\ContainerMetrics;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\HostHistoryChart;
use App\Filament\Widgets\ImageInventory;
use App\Filament\Widgets\ServiceStats;
use App\Services\SystemMonitor;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class MonitoringDashboardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
    }

    public function test_dashboards_have_separate_widgets_and_require_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/services')->assertRedirect('/admin/login');
        $this->assertSame([DashboardStats::class, HostHistoryChart::class], (new SystemDashboard)->getWidgets());
        $this->assertSame([ServiceStats::class, ContainerMetrics::class, ContainerHistoryChart::class, ImageInventory::class], (new ServicesDashboard)->getWidgets());
    }

    public function test_service_cards_and_image_inventory_render_real_api_fields(): void
    {
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => Http::response(['containers_total' => 3, 'containers_running' => 2, 'containers_stopped' => 1]),
            'monitor.test/api/images*' => Http::response(['items' => [['id' => 'sha256:abc', 'tags' => ['app:latest'], 'size_mb' => 73.38]], 'total_items' => 1, 'total_pages' => 1]),
        ]);
        Livewire::test(ServiceStats::class)->assertSee('سالم')->assertSee('کانتینرهای متوقف')->assertSee('1');
        Livewire::test(ImageInventory::class)->assertSee('app:latest')->assertSee('73.4 MB');
    }

    public function test_container_metrics_are_paginated_and_cpu_can_exceed_one_hundred(): void
    {
        Http::fake(['monitor.test/api/stats*' => function ($request) {
            $this->assertSame('true', $request['all']);

            return Http::response(['items' => [['id' => 'id'.$request['page'], 'name' => 'service-'.$request['page'], 'status' => 'running', 'cpu_percent' => 140.2, 'mem_used_mb' => 0]], 'total_items' => 11, 'total_pages' => 2]);
        }]);
        Livewire::test(ContainerMetrics::class)
            ->assertSee('service-1')->assertSee('140.2 %')->assertSee('0.0')
            ->call('nextPage', 'ContainerMetricsPage')->assertSet('paginators.ContainerMetricsPage', 2)->assertSee('service-2')->assertDontSee('service-1')
            ->call('previousPage', 'ContainerMetricsPage')->assertSet('paginators.ContainerMetricsPage', 1);
    }

    public function test_history_columns_are_mapped_and_chart_points_are_chronological(): void
    {
        Http::fake(['monitor.test/api/metrics/host/history*' => Http::response([
            'columns' => ['timestamp', 'mem_percent', 'cpu_percent', 'disk_percent'],
            'rows' => [['2026-10-19T09:01:00', 22, 8, 50], ['2026-10-19T09:00:00', 20, null, 49], ['bad-row']],
        ])]);
        $component = Livewire::test(HostHistoryChart::class);
        $data = (new \ReflectionMethod(HostHistoryChart::class, 'getData'))->invoke($component->instance());
        $this->assertSame(['2026-10-19 09:00:00', '2026-10-19 09:01:00'], $data['labels']);
        $this->assertSame([null, 8.0], $data['datasets'][0]['data']);
        $this->assertSame([20.0, 22.0], $data['datasets'][1]['data']);
    }

    public function test_selected_service_filters_history_requests(): void
    {
        Http::fake([
            'monitor.test/api/containers*' => Http::response(['items' => [['id' => 'a', 'name' => 'app-a'], ['id' => 'b', 'name' => 'app-b']]]),
            'monitor.test/api/metrics/containers/history*' => Http::response(['columns' => ['timestamp', 'cpu_percent', 'mem_percent', 'uptime'], 'rows' => [['2026-10-19T09:00:00', 101, 10, '01:00:00']]]),
        ]);
        Livewire::test(ContainerHistoryChart::class)->set('filter', 'b')->assertSee('app-b');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/metrics/containers/history') && $request['container_id'] === 'b');
    }

    public function test_history_and_inventory_failures_have_explicit_empty_states_and_are_cached(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Livewire::test(HostHistoryChart::class)->assertSee('تاریخچه در دسترس نیست');
        Livewire::test(ContainerMetrics::class)->assertSee('اطلاعات سرویس‌ها در دسترس نیست');
        Livewire::test(ImageInventory::class)->assertSee('فهرست Imageها در دسترس نیست');
        app(SystemMonitor::class)->fetch('/api/images', ['page' => 1, 'page_size' => 10]);
        Http::assertSentCount(4);
    }

    public function test_native_log_action_loads_only_selected_container_and_escapes_logs(): void
    {
        $id = 'ad035c5f2ae5';
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => $id, 'name' => 'selected-service']]]),
            'monitor.test/api/containers/'.$id.'/logs*' => Http::response(['logs' => ['<script>alert(1)</script>', 'test log']]),
        ]);
        $table = Livewire::test(ContainerMetrics::class)->assertSeeHtml('fi-ta');
        Http::assertSentCount(1);
        $table->mountTableAction('logs', $id)->assertTableActionMounted('logs')
            ->assertSeeLivewire(ContainerLogs::class)->assertSee('test log')
            ->assertDontSeeHtml('<script>alert(1)</script>');
        Http::assertSentCount(2);
        $table->unmountTableAction()->assertSet('mountedTableActions', []);
    }

    public function test_invalid_or_missing_container_ids_cannot_request_logs(): void
    {
        Http::fake(['monitor.test/api/stats*' => Http::response(['items' => []])]);
        Livewire::test(ContainerMetrics::class)->call('mountTableAction', 'logs', '../invalid')
            ->assertSet('mountedTableActions', []);
        Livewire::test(ContainerLogs::class, ['containerId' => '../invalid']);
        Http::assertSentCount(1);
    }

    public function test_logs_keep_api_pagination_while_searching_and_copy_only_matches(): void
    {
        Http::fake(['monitor.test/api/containers/*/logs*' => function ($request) {
            return Http::response(['logs' => $request['page'] == 1 ? array_fill(0, 50, 'recent line') : ['older line', 'ERROR failed']]);
        }]);
        $logs = Livewire::test(ContainerLogs::class, ['containerId' => 'ad035c5f2ae5', 'containerName' => 'first-service'])
            ->assertSee('recent line')->assertSet('lastAccessiblePage', 2)
            ->call('gotoPage', 2, 'ContainerLogsPage')->assertSee('older line')
            ->assertSet('paginators.ContainerLogsPage', 2)
            ->set('tableSearch', 'error')->assertSee('ERROR failed')->assertDontSee('older line')
            ->assertSet('paginators.ContainerLogsPage', 2);
        $copy = $logs->instance()->getTable()->getAction('copy')->getAlpineClickHandler();
        $this->assertStringContainsString('ERROR failed', $copy);
        $this->assertStringNotContainsString('older line', $copy);
        $this->assertStringContainsString("execCommand('copy')", $copy);
        $logs->call('nextPage', 'ContainerLogsPage')->assertSet('paginators.ContainerLogsPage', 2)
            ->set('tableSearch', 'no matches')->assertSee(__('monitoring.logs.no_matches'))
            ->set('tableSearch', '')->assertSee('older line')
            ->call('previousPage', 'ContainerLogsPage')->assertSee('recent line');
        Livewire::test(ContainerLogs::class, ['containerId' => '61638b47b7aa', 'containerName' => 'second-service'])
            ->assertSee('second-service')->assertDontSee('first-service')->assertSee('recent line');
        Http::assertSentCount(3);
    }

    public function test_log_errors_can_be_retried_and_identity_cannot_be_changed_by_client(): void
    {
        Http::fake(['monitor.test/api/containers/*/logs*' => Http::sequence()->pushStatus(503)->push(['logs' => []])->push(['logs' => ['fresh log']])]);
        $logs = Livewire::test(ContainerLogs::class, ['containerId' => 'ad035c5f2ae5'])
            ->assertSee(__('monitoring.logs.unavailable'));
        $logs->callTableAction('refresh')->assertSee(__('monitoring.logs.empty'))
            ->callTableAction('refresh')->assertSee('fresh log');
        Http::assertSentCount(3);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $logs->set('containerId', '61638b47b7aa');
    }
}
