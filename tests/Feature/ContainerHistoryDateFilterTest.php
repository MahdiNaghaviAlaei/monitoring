<?php

namespace Tests\Feature;

use App\Filament\Widgets\ContainerHistoryChart;
use App\Services\SystemMonitor;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ContainerHistoryDateFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test']);
        app()->setLocale('fa');
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(10, 0));
        Http::preventStrayRequests();
    }

    public function test_default_interval_is_today_and_previous_four_days_even_while_offline(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        Livewire::test(ContainerHistoryChart::class)
            ->assertSet('appliedFrom', '2026-10-17')->assertSet('appliedTo', '2026-10-21')
            ->assertSet('dateFrom', '1405/07/25')->assertSet('dateTo', '1405/07/29')
            ->assertSee('فهرست سرویس‌ها خالی است یا در دسترس نیست');
        Http::assertSentCount(1);
    }

    public function test_jalali_conversion_handles_leap_days_and_persian_and_arabic_digits(): void
    {
        $this->assertSame('2025-03-20', JalaliDate::toGregorian('۱۴۰۳/۱۲/۳۰'));
        $this->assertSame('2025-03-21', JalaliDate::toGregorian('١٤٠٤/٠١/٠١'));
        $this->assertNull(JalaliDate::toGregorian('1404/12/30'));
        $this->assertNull(JalaliDate::toGregorian('1405/07/31'));
        $this->assertNull(JalaliDate::toGregorian('2026-10-21'));
        $this->assertSame('1404/01/01', JalaliDate::fromGregorian('2025-03-21'));
        $this->assertSame('1404/01/01 00:15:00', JalaliDate::timestampLabel('2025-03-21T00:15:00+03:30'));
        $this->assertNull(JalaliDate::apiTimestamp('2025-02-30T00:00:00'));
        $previous = ini_set('intl.use_exceptions', '1');
        try {
            $this->assertNull(JalaliDate::toGregorian('1404/12/30'));
        } finally {
            ini_set('intl.use_exceptions', $previous);
        }
    }

    public function test_custom_interval_includes_both_whole_days_and_only_plots_matching_rows(): void
    {
        $this->fakeHistory([
            ['2026-10-20 00:00:00', 999, 80, 'newest'],
            ['2026-10-19 23:59:59', 150, 20, 'latest-in-range'],
            ['2026-10-18 00:00:00', 0, 0, 'first-in-range'],
            ['2026-10-17 23:59:59', 70, 50, 'old'],
        ]);
        $component = Livewire::test(ContainerHistoryChart::class)
            ->set('dateFrom', '۱۴۰۵/۰۷/۲۶')->set('dateTo', '١٤٠٥/٠٧/٢٧')->call('applyDateRange')
            ->assertDispatched('monitor-history-range-applied')
            ->assertHasNoErrors()->assertSet('appliedFrom', '2026-10-18')->assertSet('appliedTo', '2026-10-19')
            ->assertSee('بازهٔ اعمال‌شده: 1405/07/26 تا 1405/07/27');
        $data = $this->chartData($component);
        $this->assertSame(['1405/07/26', '1405/07/27'], $data['labels']);
        $this->assertSame([0.0, 150.0], $data['datasets'][0]['data']);
        $component->call('resetDateRange')->assertSet('appliedFrom', '2026-10-17')->assertSet('appliedTo', '2026-10-21');
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || isset($request['start_time']) || isset($request['end_time']));
    }

    public function test_invalid_or_reversed_dates_keep_the_previously_applied_range(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);
        $component = Livewire::test(ContainerHistoryChart::class);
        foreach (['1405/07/31', '1404/12/30', '1405/13/01', 'not a date', ''] as $date) {
            $component->set('dateFrom', $date)->call('applyDateRange')->assertHasErrors('dateFrom')
                ->assertNotDispatched('monitor-history-range-applied')
                ->assertSet('appliedFrom', '2026-10-17');
        }
        $component->set('dateFrom', '1405/07/29')->set('dateTo', '1405/07/25')->call('applyDateRange')
            ->assertHasErrors('dateTo')->assertSet('appliedTo', '2026-10-21');
        $component->set('dateTo', '1405/07/29')->call('applyDateRange')->assertHasNoErrors()
            ->assertSet('appliedFrom', '2026-10-21');
        Http::assertSentCount(1);
    }

    public function test_applied_dates_cannot_be_changed_without_server_validation(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ContainerHistoryChart::class)->set('appliedFrom', '1900-01-01');
    }

    public function test_date_filter_reads_later_pages_instead_of_filtering_only_the_first_page(): void
    {
        Http::fake(['monitor.test/api/metrics/containers/history*' => function ($request) {
            return Http::response([
                'columns' => ['timestamp', 'cpu_percent'], 'total_pages' => 3,
                'rows' => match ((int) $request['page']) {
                    1 => [['2026-10-21 00:00:00', 80]],
                    2 => [['2026-10-19 23:59:59', 20], ['2026-10-18 00:00:00', 0]],
                    3 => [['2026-10-17 23:59:59', 99]],
                },
            ]);
        }]);
        $result = app(SystemMonitor::class)->containerHistory('service-a', '2026-10-18', '2026-10-19');
        $this->assertTrue($result['complete']);
        $this->assertFalse($result['failed']);
        $this->assertSame([0.0, 20.0], array_column($result['rows'], 'cpu_percent'));
        Http::assertSentCount(3);
    }

    public function test_pagination_limits_and_midway_failures_are_reported_as_incomplete(): void
    {
        Http::fake(['monitor.test/api/metrics/containers/history*' => function ($request) {
            return $request['page'] == 2 ? Http::response([], 503) : Http::response([
                'columns' => ['timestamp', 'cpu_percent'], 'total_pages' => 3,
                'rows' => [['2026-10-20 12:00:00', 60], ['2026-10-19 12:00:00', 20]],
            ]);
        }]);
        $limited = app(SystemMonitor::class)->containerHistory('service-a', '2026-10-18', '2026-10-20', 1);
        $this->assertFalse($limited['complete']);
        $this->assertFalse($limited['failed']);
        $this->assertSame([null, 60.0], array_column($limited['rows'], 'cpu_percent'));
        $failed = app(SystemMonitor::class)->containerHistory('service-a', '2026-10-18', '2026-10-20', 3);
        $this->assertFalse($failed['complete']);
        $this->assertTrue($failed['failed']);
        $this->assertSame([null, 60.0], array_column($failed['rows'], 'cpu_percent'));
        Http::assertSentCount(2);
    }

    public function test_daily_average_includes_more_than_sixty_samples_across_pages_without_counting_overlap_twice(): void
    {
        $rows = [['2026-10-20 00:00:00', 100, null]];
        for ($minute = 59; $minute >= 0; $minute--) {
            $rows[] = [sprintf('2026-10-19 12:%02d:00', $minute), 60, 20];
        }
        Http::fake(['monitor.test/api/metrics/containers/history*' => fn ($request) => Http::response([
            'columns' => ['timestamp', 'cpu_percent', 'mem_percent'], 'total_pages' => 2,
            'rows' => $request['page'] == 1 ? $rows : [
                ['2026-10-19 12:00:00', 60, 20], // Offset pagination overlaps the previous page.
                ['2026-10-19 11:59:00', 0, 80],
                ['2026-10-18 00:00:00', 0, 0],
            ],
        ])]);
        $monitor = app(SystemMonitor::class);
        $result = $monitor->containerHistory('service-a', '2026-10-18', '2026-10-20');
        $this->assertTrue($result['complete']);
        $this->assertSame(63, $result['samples']);
        $this->assertSame(['2026-10-18', '2026-10-19', '2026-10-20'], array_column($result['rows'], 'date'));
        $this->assertSame([0.0, 59.02, 100.0], array_column($result['rows'], 'cpu_percent'));
        $this->assertSame([0.0, 20.98, null], array_column($result['rows'], 'mem_percent'));
        $this->assertSame($result, $monitor->containerHistory('service-a', '2026-10-18', '2026-10-20'));
        Http::assertSentCount(2);
    }

    public function test_ten_day_range_has_ten_dates_and_missing_days_are_gaps_not_zeroes(): void
    {
        $this->fakeHistory([
            ['2026-10-19 23:00:00', 150, 20, 'up'],
            ['2026-10-18 00:00:00', 0, 0, 'up'],
        ]);
        $component = Livewire::test(ContainerHistoryChart::class)
            ->set('dateFrom', '1405/07/20')->set('dateTo', '1405/07/29')->call('applyDateRange')
            ->set('filter', 'service-b')->assertSet('appliedFrom', '2026-10-12')->assertSet('historyPageLimit', 100);
        $data = $this->chartData($component);
        $this->assertSame(array_map(fn ($day) => '1405/07/'.$day, range(20, 29)), $data['labels']);
        $this->assertSame([null, null, null, null, null, null, 0.0, 150.0, null, null], $data['datasets'][0]['data']);
        Http::assertSent(fn ($request) => ($request['container_id'] ?? null) === 'service-b');
    }

    public function test_invalid_metrics_are_excluded_from_their_own_daily_average_and_cpu_can_exceed_one_hundred(): void
    {
        $this->fakeHistory([
            ['2026-10-19 12:04:00', 'invalid', -1, 'up'],
            ['2026-10-19 12:03:00', 0, 0, 'up'],
            ['2026-10-19 12:02:00', 200, null, 'up'],
            ['2026-10-19 12:01:00', 400, 120, 'up'],
            ['2026-10-19 12:00:00', null, 60, 'up'],
            ['2026-10-18 00:00:00', null, null, 'up'],
            ['invalid date', 100, 100, 'up'],
        ]);
        $result = app(SystemMonitor::class)->containerHistory('service-a', '2026-10-18', '2026-10-19');
        $this->assertSame([null, 200.0], array_column($result['rows'], 'cpu_percent'));
        $this->assertSame([null, 30.0], array_column($result['rows'], 'mem_percent'));
    }

    public function test_presets_use_tehran_today_and_keep_exact_day_counts_across_year_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-02T22:00:00Z'));
        Http::fake(['*' => Http::response(['items' => []])]);
        $component = Livewire::test(ContainerHistoryChart::class);
        foreach ([
            'today' => ['2026-01-03', '2026-01-03', 24],
            'yesterday' => ['2026-01-02', '2026-01-02', 24],
            'last_week' => ['2025-12-28', '2026-01-03', 7],
            'last_month' => ['2025-12-05', '2026-01-03', 30],
        ] as $preset => [$from, $to, $count]) {
            $component->call('setDatePreset', $preset)->assertHasNoErrors()
                ->assertSet('appliedFrom', $from)->assertSet('appliedTo', $to)
                ->assertSet('dateFrom', JalaliDate::fromGregorian($from))
                ->assertSet('dateTo', JalaliDate::fromGregorian($to))
                ->assertSet('activePreset', $preset)
                ->assertDispatched('monitor-history-range-applied');
            $this->assertSame(__('monitoring.history.presets.'.$preset), $component->instance()->getRangeButtonLabel());
            $this->assertCount($count, $this->chartData($component)['labels']);
        }
        $component->call('setDatePreset', 'invalid')->assertSet('appliedFrom', '2025-12-05');
    }

    public function test_excessive_range_does_not_replace_the_current_range(): void
    {
        Http::fake(['*' => Http::response(['items' => []])]);
        Livewire::test(ContainerHistoryChart::class)
            ->set('dateFrom', JalaliDate::fromGregorian('2025-01-01'))
            ->set('dateTo', JalaliDate::fromGregorian('2026-01-02'))
            ->call('applyDateRange')->assertHasErrors('dateTo')
            ->assertSet('appliedFrom', '2026-10-17');
    }

    public function test_single_day_uses_hourly_means_and_switching_to_multiple_days_restores_daily_means(): void
    {
        $this->fakeHistory([
            ['2026-10-20 00:00:00', 999, 99, 'up'],
            ['2026-10-19 23:59:59', 150, 80, 'up'],
            ['2026-10-19 01:59:59', 60, 40, 'up'],
            ['2026-10-19 01:00:00', 20, 20, 'up'],
            ['2026-10-19 00:00:00', 0, 0, 'up'],
            ['2026-10-18 23:59:59', 900, 99, 'up'],
        ]);
        $component = Livewire::test(ContainerHistoryChart::class)
            ->set('dateFrom', '1405/07/27')->set('dateTo', '1405/07/27')->call('applyDateRange')
            ->assertHasNoErrors()->assertSet('activePreset', null)
            ->assertDispatched('updateChartData');
        $data = $this->chartData($component);
        $this->assertSame('1405/07/27', $component->instance()->getRangeButtonLabel());
        $this->assertSame(array_map(fn ($hour) => sprintf('%02d:00', $hour), range(0, 23)), $data['labels']);
        $this->assertSame([0.0, 40.0, ...array_fill(0, 21, null), 150.0], $data['datasets'][0]['data']);
        $this->assertSame([0.0, 30.0, ...array_fill(0, 21, null), 80.0], $data['datasets'][1]['data']);
        $this->assertSame(__('monitoring.history.hourly_cpu'), $data['datasets'][0]['label']);

        $component->set('dateTo', '1405/07/28')->call('applyDateRange');
        $data = $this->chartData($component);
        $this->assertSame(['1405/07/27', '1405/07/28'], $data['labels']);
        $this->assertSame([57.5, 999.0], $data['datasets'][0]['data']);
        $this->assertSame(__('monitoring.history.daily_cpu'), $data['datasets'][0]['label']);
        $this->assertSame(__('monitoring.history.custom_range'), $component->instance()->getRangeButtonLabel());
    }

    public function test_hour_split_across_pages_is_averaged_only_when_all_its_samples_are_read(): void
    {
        Http::fake(['monitor.test/api/metrics/containers/history*' => fn ($request) => Http::response([
            'columns' => ['timestamp', 'cpu_percent', 'mem_percent'], 'total_pages' => 2,
            'rows' => $request['page'] == 1 ? [
                ['2026-10-19T13:00:00', 80, 40],
                ['2026-10-19T12:59:59', 90, 30],
            ] : [
                ['2026-10-19T12:59:59', 90, 30],
                ['2026-10-19T12:30:00', 30, 60],
                ['2026-10-19T12:00:00', 0, null],
                ['2026-10-18T23:59:59', 999, 99],
            ],
        ])]);
        $monitor = app(SystemMonitor::class);
        $partial = $monitor->containerHistory('service-a', '2026-10-19', '2026-10-19', 1);
        $this->assertFalse($partial['complete']);
        $this->assertSame([null, 80.0], array_column($partial['rows'], 'cpu_percent'));
        $complete = $monitor->containerHistory('service-a', '2026-10-19', '2026-10-19', 2);
        $this->assertTrue($complete['complete']);
        $this->assertSame(4, $complete['samples']);
        $this->assertSame(['2026-10-19 12:00:00', '2026-10-19 13:00:00'], array_column($complete['rows'], 'date'));
        $this->assertSame([40.0, 80.0], array_column($complete['rows'], 'cpu_percent'));
        $this->assertSame([45.0, 40.0], array_column($complete['rows'], 'mem_percent'));
        Http::assertSentCount(2);
    }

    public function test_invalid_custom_range_keeps_preset_label_and_service_changes_keep_selection(): void
    {
        $this->fakeHistory([]);
        $component = Livewire::test(ContainerHistoryChart::class)->call('setDatePreset', 'last_week')
            ->set('dateFrom', 'invalid')->call('applyDateRange')->assertHasErrors('dateFrom')
            ->assertSet('activePreset', 'last_week')->assertSet('appliedFrom', '2026-10-15')
            ->set('filter', 'service-b')->assertSet('activePreset', 'last_week');
        $this->assertSame('هفتهٔ اخیر', $component->instance()->getRangeButtonLabel());
        $component->call('setDatePreset', 'yesterday')->assertHasNoErrors()->assertSet('activePreset', 'yesterday');
        $this->assertSame('دیروز', $component->instance()->getRangeButtonLabel());
    }

    private function fakeHistory(array $rows, int $pages = 1): void
    {
        Http::fake([
            'monitor.test/api/containers*' => Http::response(['items' => [['id' => 'service-a', 'name' => 'app-a'], ['id' => 'service-b', 'name' => 'app-b']]]),
            'monitor.test/api/metrics/containers/history*' => Http::response([
                'columns' => ['timestamp', 'cpu_percent', 'mem_percent', 'uptime'], 'rows' => $rows, 'total_pages' => $pages,
            ]),
        ]);
    }

    private function chartData($component): array
    {
        return (new \ReflectionMethod(ContainerHistoryChart::class, 'getCachedData'))->invoke($component->instance());
    }
}
