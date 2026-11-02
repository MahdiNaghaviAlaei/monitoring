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
use App\Support\MonitorFormat;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MonitoringTranslationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fa');
        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
        // Load the actual catalog before overriding selected copy as a user would.
        $this->assertSame('کل کانتینرها', __('monitoring.services.containers_total'));
    }

    public function test_card_page_and_chart_labels_are_read_from_the_translation_catalog(): void
    {
        app('translator')->addLines([
            'monitoring.pages.systems' => 'سیستم‌های من',
            'monitoring.pages.services' => 'سرویس‌های من',
            'monitoring.system.heading' => 'منابع میزبان من',
            'monitoring.system.cpu' => 'پردازندهٔ میزبان من',
            'monitoring.system.description' => 'ساعت بررسی :time',
            'monitoring.services.containers_total' => 'تعداد همهٔ سرویس‌ها',
            'monitoring.history.host_heading' => 'روند سیستم من',
            'monitoring.history.container_heading' => 'روند سرویس من',
        ], 'fa');
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => Http::response(['host' => ['cpu_percent' => 12], 'containers_total' => 4]),
            'monitor.test/api/images*' => Http::response(['items' => [], 'total_items' => 0]),
            'monitor.test/api/metrics/host/history*' => Http::response(['columns' => [], 'rows' => []]),
            'monitor.test/api/containers*' => Http::response(['items' => []]),
        ]);

        $this->assertSame('سیستم‌های من', (new SystemDashboard)->getTitle());
        $this->assertSame('سیستم‌های من', SystemDashboard::getNavigationLabel());
        $this->assertSame('سرویس‌های من', (new ServicesDashboard)->getTitle());
        $this->assertSame('سرویس‌های من', ServicesDashboard::getNavigationLabel());
        Livewire::test(DashboardStats::class)->assertSee('منابع میزبان من')->assertSee('پردازندهٔ میزبان من')
            ->assertSee('ساعت بررسی '.app(SystemMonitor::class)->snapshot()['checked_at'])->assertDontSee(':time');
        Livewire::test(ServiceStats::class)->assertSee('تعداد همهٔ سرویس‌ها');
        Livewire::test(HostHistoryChart::class)->assertSee('روند سیستم من');
        Livewire::test(ContainerHistoryChart::class)->assertSee('روند سرویس من');
    }

    public function test_table_modal_and_status_copy_is_translatable_and_api_names_remain_escaped(): void
    {
        app('translator')->addLines([
            'monitoring.containers.heading' => 'جدول من',
            'monitoring.containers.columns.service' => 'نام برنامه',
            'monitoring.status.exited' => 'خاموش',
            'monitoring.control.confirm_message' => 'اجرای :operation روی :name؟',
            'monitoring.logs.heading' => 'گزارش برنامهٔ من',
            'monitoring.logs.pagination_label' => 'صفحات گزارش من',
            'monitoring.images.heading' => 'موجودی من',
            'monitoring.pagination.summary' => 'نمایش :page / :pages با :total مورد',
        ], 'fa');
        $id = 'ad035c5f2ae5';
        $name = '<script>alert("service")</script>';
        Http::fake([
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => $id, 'name' => $name, 'status' => 'exited']], 'total_items' => 1, 'total_pages' => 1]),
            'monitor.test/api/images*' => Http::response(['items' => [], 'total_items' => 0]),
        ]);

        Livewire::test(ContainerMetrics::class)->assertSee('جدول من')->assertSee('نام برنامه')->assertSee('خاموش')
            ->mountTableAction('start', $id)
            ->assertSee('اجرای راه‌اندازی روی '.$name.'؟')->assertDontSeeHtml($name)->assertDontSee(':name');
        Livewire::test(ContainerLogs::class)->assertSee('گزارش برنامهٔ من')->assertSeeHtml('fi-ta');
        Livewire::test(ImageInventory::class)->assertSee('موجودی من');
        $this->assertSame('خاموش', MonitorFormat::status('exited'));
        $this->assertSame('unexpected-status', MonitorFormat::status('unexpected-status'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_protection_and_api_error_messages_use_catalog_text(): void
    {
        app('translator')->addLines([
            'monitoring.control.protected_title' => 'این سرویس را روشن نگه دارید',
            'monitoring.control.invalid_request' => 'اطلاعات عملیات نادرست است',
        ], 'fa');
        config(['system-monitor.protected_containers' => ['system_monitor']]);
        Http::fake(['monitor.test/api/stats*' => Http::response([
            'items' => [['id' => 'ad035c5f2ae5', 'name' => 'worker', 'status' => 'running']],
        ]), 'monitor.test/api/containers/ad035c5f2ae5/stats' => Http::response(['name' => 'system_monitor', 'status' => 'running'])]);

        Livewire::test(ContainerMetrics::class)->mountTableAction('stop', 'ad035c5f2ae5')->callMountedTableAction();
        Notification::assertNotified('این سرویس را روشن نگه دارید');
        $this->assertSame('اطلاعات عملیات نادرست است', app(SystemMonitor::class)->control('../invalid', 'start')['message']);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }
}
