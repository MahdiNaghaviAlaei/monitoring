<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected static ?string $pollingInterval = '10s';

    protected function getHeading(): ?string
    {
        return __('monitoring.system.heading');
    }

    protected function getStats(): array
    {
        $snapshot = app(SystemMonitor::class)->snapshot();
        $host = data_get($snapshot, 'summary.host', []);
        $host = is_array($host) ? $host : [];

        $this->description = __('monitoring.system.description', ['time' => $snapshot['checked_at']]);

        return [
            $this->usage('host-cpu', __('monitoring.system.cpu'), $host['cpu_percent'] ?? null, 'heroicon-o-cpu-chip')
                ->description(isset($host['hostname']) && is_string($host['hostname']) ? __('monitoring.system.host_description', ['name' => $host['hostname']]) : __('monitoring.system.host_unavailable')),

            $this->usage('host-memory', __('monitoring.system.memory'), $host['mem_percent'] ?? null, 'heroicon-o-server')
                ->description($this->capacity($host['mem_used_mb'] ?? null, $host['mem_total_mb'] ?? null, 'MB')),

            $this->usage('host-disk', __('monitoring.system.disk'), $host['disk_percent'] ?? null, 'heroicon-o-circle-stack')
                ->description($this->capacity($host['disk_used_gb'] ?? null, $host['disk_total_gb'] ?? null, 'GB')),

            Stat::make(__('monitoring.system.uptime'), is_string($host['uptime'] ?? null) ? $host['uptime'] : __('monitoring.common.unavailable'))
                ->id('host-uptime')
                ->icon('heroicon-o-clock')
                ->description(__('monitoring.system.uptime_description')),

            Stat::make(__('monitoring.system.hostname'), is_string($host['hostname'] ?? null) ? $host['hostname'] : __('monitoring.common.unavailable'))
                ->id('host-name')
                ->icon('heroicon-o-server-stack')
                ->description(__('monitoring.system.measured_at', ['time' => is_string($host['timestamp'] ?? null) ? $host['timestamp'] : __('monitoring.common.unknown')])),
        ];
    }

    private function usage(string $id, string $label, mixed $value, string $icon): Stat
    {
        $valid = is_numeric($value) && $value >= 0 && $value <= 100;

        return Stat::make($label, $valid ? __('monitoring.common.percentage', ['value' => number_format((float) $value, 1)]) : __('monitoring.common.unavailable'))
            ->id($id)
            ->icon($icon)
            ->color(! $valid ? 'gray' : ($value >= 90 ? 'danger' : ($value >= 75 ? 'warning' : 'success')));
    }

    private function capacity(mixed $used, mixed $total, string $unit): string
    {
        if (! is_numeric($used) || ! is_numeric($total) || $used < 0 || $total <= 0) {
            return __('monitoring.system.capacity_unavailable');
        }

        return __('monitoring.system.capacity', ['used' => number_format((float) $used, 1), 'total' => number_format((float) $total, 1), 'unit' => $unit]);
    }
}
