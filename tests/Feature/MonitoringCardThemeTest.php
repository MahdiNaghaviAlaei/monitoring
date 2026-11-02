<?php

namespace Tests\Feature;

use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\ServiceStats;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MonitoringCardThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['system-monitor.url' => 'http://monitor.test']);
        Http::preventStrayRequests();
    }

    public function test_native_stats_distinguish_zero_full_usage_and_missing_data(): void
    {
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => Http::response(['host' => ['cpu_percent' => 0, 'mem_percent' => 100, 'disk_percent' => null]]),
        ]);
        $widget = Livewire::test(DashboardStats::class)->assertSeeHtml('wire:poll.10s')
            ->assertDontSeeHtml('monitor-card')->assertDontSeeHtml('role="meter"');
        $this->assertSame(['0.0٪', '100.0٪', 'در دسترس نیست', 'در دسترس نیست', 'در دسترس نیست'], $this->values($widget->html()));
        Http::assertSentCount(2);
    }

    public function test_native_offline_cards_remain_visible_without_false_zero_values(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $widget = Livewire::test(DashboardStats::class)->assertSee(__('monitoring.system.cpu'))
            ->assertDontSee('0.0٪')->assertSeeHtml('fi-wi-stats-overview-stats-ctn');
        $this->assertSame(array_fill(0, 5, __('monitoring.common.unavailable')), $this->values($widget->html()));
        Http::assertSentCount(2);
    }

    public function test_api_text_is_escaped_in_native_stats(): void
    {
        $hostname = '<script>alert("host")</script>';
        Http::fake([
            'monitor.test/health' => Http::response(['status' => 'ok']),
            'monitor.test/api/summary' => Http::response(['host' => ['hostname' => $hostname]]),
        ]);
        Livewire::test(DashboardStats::class)->assertSee($hostname)->assertDontSeeHtml($hostname);
    }

    public function test_service_counts_and_health_use_native_values_and_refresh_without_extra_requests(): void
    {
        $summary = ['containers_total' => 3, 'containers_running' => 0, 'containers_stopped' => 3];
        $images = 4;
        Http::fake(function (Request $request) use (&$summary, &$images) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/health' => Http::response(['status' => 'ok']),
                '/api/summary' => Http::response($summary),
                '/api/images' => Http::response(['total_items' => $images]),
                default => throw new \LogicException('Unexpected request'),
            };
        });
        $widget = Livewire::test(ServiceStats::class)->assertSeeHtml('wire:poll.30s')->assertDontSeeHtml('heroicon-o-heart');
        $this->assertSame(['سالم', '3', '0', '3', '4'], $this->values($widget->html()));
        Http::assertSentCount(3);
        $this->travel(11)->seconds();
        $summary['containers_total'] = 5;
        $images = 6;
        $widget->call('$refresh');
        $this->assertSame(['سالم', '5', '0', '3', '6'], $this->values($widget->html()));
        Http::assertSentCount(6);
        $this->travel(11)->seconds();
        $summary = [];
        $images = null;
        $widget->call('$refresh');
        $this->assertSame(['سالم', ...array_fill(0, 4, __('monitoring.common.unavailable'))], $this->values($widget->html()));
        Http::assertSentCount(9);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || str_contains($request->url(), '/history'));
    }

    private function values(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $values = [];
        foreach ((new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " fi-wi-stats-overview-stat-value ")]') as $node) {
            $values[] = trim($node->textContent);
        }

        return $values;
    }
}
