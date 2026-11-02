<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class ServiceStats extends StatsOverviewWidget
{
    protected function getHeading(): ?string
    {
        return __('monitoring.services.heading');
    }

    protected static ?string $pollingInterval = '30s';

    #[On('monitor-services-updated')]
    public function refreshStats(): void {}

    protected function getStats(): array
    {

        $monitor = app(SystemMonitor::class);
        $snapshot = $monitor->snapshot();
        $health = data_get($snapshot, 'health.status');
        $images = $monitor->fetch('/api/images', ['page' => 1, 'page_size' => 10]);
        $this->description = __('monitoring.services.description', ['time' => $snapshot['checked_at']]);

        return [
            Stat::make(__('monitoring.services.health'), $health === 'ok' ? __('monitoring.common.healthy') : ($health === null ? __('monitoring.common.unavailable') : __('monitoring.common.needs_attention')))
                ->id('monitor-health')
                ->description(__('monitoring.services.health_description'))
                ->color($health === 'ok' ? 'success' : ($health === null ? 'gray' : 'danger')),
            $this->count('containers_total', __('monitoring.services.containers_total'), data_get($snapshot, 'summary.containers_total'), 'primary'),
            $this->count('containers_running', __('monitoring.services.containers_running'), data_get($snapshot, 'summary.containers_running'), 'success'),
            $this->count('containers_stopped', __('monitoring.services.containers_stopped'), data_get($snapshot, 'summary.containers_stopped'), data_get($snapshot, 'summary.containers_stopped', 0) > 0 ? 'warning' : 'success'),
            $this->count('images_total', __('monitoring.services.images_total'), $images['total_items'] ?? null, 'info'),
        ];
    }

    private function count(string $key, string $label, mixed $value, string $color): Stat
    {
        $valid = is_int($value) && $value >= 0;

        return Stat::make($label, $valid ? number_format($value) : __('monitoring.common.unavailable'))
            ->id($key)
            ->icon('heroicon-o-cube')
            ->color($valid ? $color : 'gray');
    }
}
