<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use App\Support\MonitorFormat;
use App\Support\MonitorRecord;
use Filament\Infolists\Components\Livewire;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class ContainerMetrics extends PaginatedMonitorWidget
{
    private bool $forceRefresh = false;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading(__('monitoring.containers.heading'))
            ->description(fn () => $this->statusDescription())
            ->emptyStateHeading(fn () => __(is_array($this->currentPayload()['items'] ?? null) ? 'monitoring.containers.empty' : 'monitoring.containers.unavailable'))
            ->columns([
                TextColumn::make('name')->label(__('monitoring.containers.columns.service'))
                    ->placeholder(__('monitoring.common.unknown'))->wrap()
                    ->description(fn (MonitorRecord $record) => substr((string) $record->id, 0, 12).(self::isProtectedContainer($record->id, $record->name) ? ' · '.__('monitoring.containers.protected') : '')),
                TextColumn::make('status')->label(__('monitoring.containers.columns.status'))->badge()
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::status($record->status))
                    ->color(fn (MonitorRecord $record) => $record->status === 'running' ? 'success' : ($record->status === null ? 'gray' : 'warning')),
                TextColumn::make('cpu_percent')->label(__('monitoring.containers.columns.cpu'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->cpu_percent, '%')),
                TextColumn::make('memory_usage')->label(__('monitoring.containers.columns.memory_usage'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->mem_used_mb).' / '.MonitorFormat::number($record->mem_limit_mb, 'MB')),
                TextColumn::make('mem_percent')->label(__('monitoring.containers.columns.memory_percent'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->mem_percent, '%')),
                TextColumn::make('network')->label(__('monitoring.containers.columns.network'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->net_rx_mb).' / '.MonitorFormat::number($record->net_tx_mb, 'MB')),
                TextColumn::make('disk')->label(__('monitoring.containers.columns.disk'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->block_read_mb).' / '.MonitorFormat::number($record->block_write_mb, 'MB')),
            ])
            ->headerActions([
                Action::make('refresh')->label(__('monitoring.actions.retry_connection'))
                    ->icon('heroicon-o-arrow-path')->color('gray')->action(fn () => $this->refreshServices()),
            ])
            ->actions([
                Action::make('logs')->label(__('monitoring.actions.show_logs'))->icon('heroicon-o-document-text')->color('gray')
                    ->disabled(fn (MonitorRecord $record) => ! $this->canUseRecord($record))
                    ->modalHeading(fn (MonitorRecord $record) => __('monitoring.logs.heading').' — '.$record->name)
                    ->modalWidth('7xl')->modalSubmitAction(false)->modalCancelActionLabel(__('monitoring.actions.close'))
                    ->infolist(fn (MonitorRecord $record) => [
                        Livewire::make(ContainerLogs::class, [
                            'containerId' => (string) $record->id,
                            'containerName' => is_string($record->name) ? $record->name : (string) $record->id,
                        ])->key('logs-'.$record->id)->columnSpanFull(),
                    ]),
                ...array_map(fn (string $operation) => $this->controlAction($operation), ['start', 'stop', 'restart']),
            ]);
    }

    private function statusDescription(): string
    {
        $data = $this->currentPayload();

        return match ($data['source'] ?? 'offline') {
            'metrics' => __('monitoring.containers.description'),
            'inventory' => __('monitoring.containers.inventory_only'),
            default => __('monitoring.containers.offline').' '.__('monitoring.containers.recovery').' '.__('monitoring.containers.docker_recovery', [
                'list_command' => 'docker ps -a', 'start_command' => 'docker start CONTAINER_NAME',
            ]).(isset($data['saved_at']) ? ' '.__('monitoring.containers.saved_at', ['time' => $data['saved_at']]) : ''),
        };
    }

    private function canUseRecord(MonitorRecord $record): bool
    {
        return ($this->currentPayload()['source'] ?? 'offline') !== 'offline'
            && is_string($record->id) && preg_match('/^[a-f0-9]{12,64}$/i', $record->id);
    }

    private function controlAction(string $operation): Action
    {
        return Action::make($operation)->label(__('monitoring.actions.'.$operation))
            ->icon(match ($operation) {
                'start' => 'heroicon-o-play', 'stop' => 'heroicon-o-stop', default => 'heroicon-o-arrow-path'
            })
            ->color(match ($operation) {
                'start' => 'success', 'stop' => 'danger', default => 'warning'
            })
            ->iconButton()->tooltip(__('monitoring.actions.'.$operation))
            ->disabled(fn (MonitorRecord $record) => ! $this->canUseRecord($record)
                || ! self::canControl($record->status, $operation)
                || ($operation !== 'start' && self::isProtectedContainer($record->id, $record->name)))
            ->requiresConfirmation()
            ->modalHeading(__('monitoring.control.'.$operation.'_heading'))
            ->modalDescription(fn (MonitorRecord $record) => __('monitoring.control.confirm_message', [
                'operation' => __('monitoring.actions.'.$operation), 'name' => $record->name ?? $record->id,
            ]).($operation === 'start' ? '' : ' '.__('monitoring.control.'.$operation.'_warning')))
            ->modalSubmitActionLabel(__('monitoring.actions.confirm'))
            ->modalCancelActionLabel(__('monitoring.actions.cancel'))
            ->action(fn (MonitorRecord $record) => $this->executeControl($record, $operation));
    }

    public static function isProtectedContainer(mixed $id, mixed $name): bool
    {
        $identifiers = array_map(fn ($value) => is_string($value) ? strtolower(ltrim($value, '/')) : '', [$id, $name]);
        foreach (config('system-monitor.protected_containers', ['system_monitor']) as $protected) {
            if (in_array(strtolower(ltrim($protected, '/')), $identifiers, true)) {
                return true;
            }
        }

        return false;
    }

    public static function canControl(mixed $status, string $operation): bool
    {
        return match ($operation) {
            'start' => in_array($status, ['created', 'exited'], true),
            'stop' => in_array($status, ['running', 'restarting', 'paused'], true),
            'restart' => in_array($status, ['running', 'exited'], true),
            default => false,
        };
    }

    public function refreshServices(): void
    {
        $this->forceRefresh = true;
        $this->clearApiRecords();
    }

    protected function executeControl(MonitorRecord $record, string $operation): void
    {
        if (! $this->canUseRecord($record) || ! self::canControl($record->status, $operation)) {
            return;
        }
        $monitor = app(SystemMonitor::class);
        $current = $monitor->fetch('/api/containers/'.$record->id.'/stats', fresh: true);
        // Stopped containers may have no stats; verify their fresh inventory status.
        if (! is_string($current['status'] ?? null)) {
            $inventory = $monitor->fetch('/api/containers', [
                'page' => $this->getTablePage(), 'page_size' => 10, 'all' => 'true',
            ], fresh: true);
            $current = collect(is_array($inventory['items'] ?? null) ? $inventory['items'] : [])
                ->first(fn ($item) => is_array($item) && ($item['id'] ?? null) === $record->id);
        }
        if ($operation !== 'start' && (
            self::isProtectedContainer($record->id, $record->name)
            || self::isProtectedContainer($record->id, $current['name'] ?? null)
        )) {
            Notification::make()->title(__($operation === 'restart' ? 'monitoring.control.protected_restart_title' : 'monitoring.control.protected_title'))->warning()->send();

            return;
        }
        if (! self::canControl($current['status'] ?? null, $operation)) {
            Notification::make()->title(__('monitoring.control.not_sent'))->body(__('monitoring.control.state_changed'))->warning()->send();

            return;
        }

        $result = $monitor->control($record->id, $operation);
        $notification = Notification::make()->title($result['ok'] ? __('monitoring.control.success') : __('monitoring.control.not_confirmed'))->body(e($result['message']));
        ($result['ok'] ? $notification->success() : $notification->warning())->send();
        $this->refreshServices();
        $this->dispatch('monitor-services-updated');
    }

    protected function endpoint(): string
    {
        return '/api/stats';
    }

    protected function payload(): ?array
    {
        $monitor = app(SystemMonitor::class);
        $query = ['page' => $this->getTablePage(), 'page_size' => 10, 'all' => 'true'];
        $fresh = $this->forceRefresh;
        $this->forceRefresh = false;
        $data = $monitor->fetch('/api/stats', $query, fresh: $fresh);
        $source = 'metrics';
        if (! is_array($data['items'] ?? null)) {
            $data = $monitor->fetch('/api/containers', $query, fresh: $fresh);
            $source = 'inventory';
        }

        $key = 'system-monitor:last-containers:'.sha1(rtrim((string) config('system-monitor.url'), '/').'|'.$this->getTablePage());
        if (is_array($data['items'] ?? null)) {
            $savedAt = now()->timezone('Asia/Tehran')->format('Y-m-d H:i:s');
            Cache::put($key, ['payload' => $data, 'saved_at' => $savedAt], now()->addDay());

            return [...$data, 'source' => $source, 'saved_at' => $savedAt];
        }

        $saved = Cache::get($key);
        if (! is_array($saved['payload']['items'] ?? null)) {
            return null;
        }

        // Preserve identities, but never present cached usage/status as current or use it to send commands.
        $items = array_map(fn ($item) => ['id' => $item['id'] ?? null, 'name' => $item['name'] ?? null], array_filter($saved['payload']['items'], 'is_array'));

        return [...$saved['payload'], 'items' => $items, 'source' => 'offline', 'saved_at' => $saved['saved_at']];
    }
}
