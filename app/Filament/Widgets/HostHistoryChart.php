<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use Filament\Widgets\ChartWidget;

class HostHistoryChart extends ChartWidget
{
    public function getHeading(): string
    {
        return __('monitoring.history.host_heading');
    }

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    protected static ?array $options = ['scales' => ['y' => ['beginAtZero' => true, 'max' => 100]], 'spanGaps' => false];

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = app(SystemMonitor::class)->history('/api/metrics/host/history', ['page' => 1, 'page_size' => 60]);
        $this->historyDescription = $rows === null ? __('monitoring.history.host_unavailable') : ($rows === [] ? __('monitoring.history.host_empty') : __('monitoring.history.host_description'));
        $rows = array_reverse($rows ?? []);
        $datasets = [];
        foreach (['cpu_percent' => [__('monitoring.history.cpu'), '#f59e0b'], 'mem_percent' => [__('monitoring.history.memory'), '#3b82f6'], 'disk_percent' => [__('monitoring.history.disk'), '#22c55e']] as $field => [$label, $color]) {
            $datasets[] = ['label' => $label, 'data' => array_map(fn ($row) => is_numeric($row[$field] ?? null) && $row[$field] >= 0 && $row[$field] <= 100 ? (float) $row[$field] : null, $rows), 'borderColor' => $color, 'backgroundColor' => $color, 'pointRadius' => 1];
        }

        return ['labels' => array_map(fn ($row) => is_string($row['timestamp'] ?? null) ? substr(str_replace('T', ' ', $row['timestamp']), 0, 19) : __('monitoring.common.missing_value'), $rows), 'datasets' => $datasets];
    }

    protected ?string $historyDescription = null;

    public function getDescription(): ?string
    {
        $this->getCachedData();

        return $this->historyDescription;
    }
}
