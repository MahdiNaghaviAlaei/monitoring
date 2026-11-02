<?php

namespace Tests\Feature;

use App\Filament\Pages\ServicesDashboard;
use App\Filament\Widgets\ContainerHistoryChart;
use App\Filament\Widgets\ContainerLogs;
use App\Filament\Widgets\ContainerMetrics;
use App\Filament\Widgets\ImageInventory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class NativeMonitoringComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(10, 0));
    }

    public function test_api_tables_render_paginate_and_resolve_actions_without_database_queries(): void
    {
        DB::connection()->beforeExecuting(function (string $query): void {
            $this->fail('Monitoring must not execute SQL: '.$query);
        });
        Http::fake([
            'monitor.test/api/images*' => fn ($request) => Http::response([
                'items' => [['id' => 'sha256:'.$request['page'], 'tags' => ['image-'.$request['page']], 'size_mb' => 12]],
                'total_items' => 11, 'total_pages' => 2,
            ]),
            'monitor.test/api/stats*' => Http::response(['items' => [['id' => 'ad035c5f2ae5', 'name' => 'api-service', 'status' => 'running']]]),
            'monitor.test/api/containers/*/logs*' => Http::response(['logs' => ['log line']]),
        ]);
        Livewire::test(ImageInventory::class)->assertSeeHtml('fi-ta')->assertSee('image-1')
            ->call('gotoPage', 2, 'ImageInventoryPage')->assertSee('image-2')->assertDontSee('image-1');
        Livewire::test(ContainerMetrics::class)->mountTableAction('stop', 'ad035c5f2ae5')
            ->assertSee(__('monitoring.control.stop_warning'))->unmountTableAction()
            ->mountTableAction('logs', 'ad035c5f2ae5')->assertSeeLivewire(ContainerLogs::class)->assertSee('log line');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_widget_actions_keep_jalali_calendar_validation_and_selected_label(): void
    {
        $user = new User(['name' => 'Test', 'email' => 'test@example.com']);
        $user->id = 1;
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::fake(['*' => Http::response(['items' => [], 'columns' => [], 'rows' => []])]);
        $this->assertSame([], (new \ReflectionMethod(ServicesDashboard::class, 'getHeaderActions'))->invoke(new ServicesDashboard));
        $page = Livewire::test(ContainerHistoryChart::class)->assertSee(__('monitoring.history.presets.last_five_days'))
            ->assertDontSee('css/theme.css')
            ->callAction('last_week')->assertSet('activePreset', 'last_week')
            ->assertSet('appliedFrom', '2026-10-15')
            ->mountAction('customRange')->assertSee('تقویم شمسی')->assertSeeHtml('x-model="value"')
            ->assertActionDataSet(['dateFrom' => '1405/07/23', 'dateTo' => '1405/07/29'])
            ->setActionData(['dateFrom' => '1405/07/31', 'dateTo' => '1405/07/29'])->callMountedAction()
            ->assertHasActionErrors(['dateFrom'])->assertSet('activePreset', 'last_week');
        $page->setActionData(['dateFrom' => '1405/07/29', 'dateTo' => '1405/07/28'])->callMountedAction()
            ->assertHasActionErrors(['dateTo']);
        $page->setActionData(['dateFrom' => '1405/07/28', 'dateTo' => '1405/07/28'])->callMountedAction()
            ->assertHasNoActionErrors()->assertSet('appliedFrom', '2026-10-20')
            ->assertSet('appliedTo', '2026-10-20')->assertSet('activePreset', null);
    }

    public function test_range_dropdown_trigger_is_not_disabled_by_chart_polling(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);
        $chart = Livewire::test(ContainerHistoryChart::class);

        foreach ([false, true] as $afterPolling) {
            if ($afterPolling) {
                $chart->call('updateChartData');
            }

            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$chart->html());
            $trigger = (new \DOMXPath($document))->query('//button[@data-monitoring-range-trigger]')->item(0);
            $this->assertNotNull($trigger);
            $this->assertFalse($trigger->hasAttribute('wire:loading.attr'));
            $this->assertFalse($trigger->hasAttribute('disabled'));
            $this->assertStringContainsString('cursor-pointer', $trigger->getAttribute('class'));
        }

        $chart->callAction('last_week')->assertSet('activePreset', 'last_week')
            ->call('updateChartData')->mountAction('customRange')->assertActionMounted('customRange');
    }

    public function test_widget_actions_keep_hourly_and_daily_buckets(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);
        $chart = Livewire::test(ContainerHistoryChart::class)->assertSeeHtml('fi-wi-chart')
            ->callAction('today')->assertSet('appliedFrom', '2026-10-21')
            ->assertSet('appliedTo', '2026-10-21');
        $getData = new \ReflectionMethod(ContainerHistoryChart::class, 'getData');
        $this->assertCount(24, $getData->invoke($chart->instance())['labels']);
        $chart->call('selectDateRange', '1405/07/26', '1405/07/29')
            ->assertSet('appliedFrom', '2026-10-18')->assertSet('appliedTo', '2026-10-21');
        $this->assertCount(4, $getData->invoke($chart->instance())['labels']);
        $chart->call('loadMoreHistory')->assertSet('historyPageLimit', 200);
    }

    public function test_new_log_modal_starts_at_page_one_even_with_an_old_page_in_the_url(): void
    {
        Http::fake(['monitor.test/api/containers/*/logs*' => Http::response(['logs' => ['first page']])]);
        Livewire::withQueryParams(['ContainerLogsPage' => 7])->test(ContainerLogs::class, ['containerId' => 'ad035c5f2ae5'])
            ->assertSet('paginators.ContainerLogsPage', 1)->assertSee('first page');
        Http::assertSent(fn ($request) => (int) $request['page'] === 1);
    }
}
