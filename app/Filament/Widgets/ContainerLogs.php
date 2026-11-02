<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use App\Support\MonitorRecord;
use Filament\Forms\Components\Select;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator as SimplePaginator;
use Illuminate\Support\Js;
use Livewire\Attributes\Locked;
use Livewire\WithoutUrlPagination;

class ContainerLogs extends PaginatedMonitorWidget
{
    use WithoutUrlPagination;

    protected static bool $isLazy = false;

    #[Locked]
    public ?string $containerId = null;

    #[Locked]
    public ?string $containerName = null;

    #[Locked]
    public int $lastAccessiblePage = 1;

    public bool $wrapLines = true;

    public function mount(): void
    {
        // The table is configured after mount(). Match the base widget's identifier
        // without accessing the table before Filament has initialized it.
        $this->paginators[class_basename(static::class).'Page'] = 1;
    }

    protected function endpoint(): string
    {
        return '/api/containers/'.$this->containerId.'/logs';
    }

    public function table(Table $table): Table
    {
        return parent::table($table)->poll(null)->paginationPageOptions([50])
            ->heading(fn () => $this->containerName ?? __('monitoring.logs.heading'))
            ->description(fn () => ($this->containerId ?? '').' · '.__('monitoring.logs.search_hint'))
            ->searchable()->searchPlaceholder(__('monitoring.logs.search_placeholder'))
            ->emptyStateHeading(fn () => __(! is_array($this->currentPayload()['logs'] ?? null)
                ? 'monitoring.logs.unavailable' : (filled($this->tableSearch) ? 'monitoring.logs.no_matches' : 'monitoring.logs.empty')))
            ->columns([
                TextColumn::make('number')->label(__('monitoring.logs.line_number'))->color('gray'),
                TextColumn::make('line')->label(__('monitoring.logs.output'))->fontFamily(FontFamily::Mono)
                    ->wrap(fn () => $this->wrapLines)
                    ->color(fn (MonitorRecord $record) => preg_match('/\b(error|fatal|exception)\b/i', $record->line) ? 'danger'
                        : (preg_match('/\bwarn(ing)?\b/i', $record->line) ? 'warning' : 'gray')),
            ])
            ->headerActions([
                Action::make('refresh')->label(__('monitoring.actions.refresh_logs'))->icon('heroicon-o-arrow-path')
                    ->color('gray')->action(fn () => $this->refreshLogs()),
                Action::make('copy')->label(__('monitoring.logs.copy'))->icon('heroicon-o-clipboard-document')
                    ->color('gray')->disabled(fn () => $this->getTableRecords()->isEmpty())
                    ->alpineClickHandler(fn () => $this->copyHandler()),
                Action::make('wrap')->label(__('monitoring.logs.wrap_lines'))->icon('heroicon-o-bars-3-bottom-left')
                    ->color(fn () => $this->wrapLines ? 'primary' : 'gray')
                    ->action(fn () => $this->wrapLines = ! $this->wrapLines),
                Action::make('page')->label(fn () => __('monitoring.logs.page').' '.$this->getTablePage())->color('gray')
                    ->form([Select::make('page')->label(__('monitoring.logs.page'))->required()
                        ->options(fn () => array_combine(range(1, $this->lastAccessiblePage), range(1, $this->lastAccessiblePage)))
                        ->default(fn () => $this->getTablePage())])
                    ->action(fn (array $data) => $this->gotoPage((int) $data['page'], $this->getTablePaginationPageName())),
            ]);
    }

    protected function payload(): ?array
    {
        if (! is_string($this->containerId) || ! preg_match('/^[a-f0-9]{12,64}$/i', $this->containerId)) {
            return null;
        }

        return app(SystemMonitor::class)->fetch($this->endpoint(), ['page' => $this->getTablePage(), 'page_size' => 50]);
    }

    public function refreshLogs(): void
    {
        if (is_string($this->containerId) && preg_match('/^[a-f0-9]{12,64}$/i', $this->containerId)) {
            app(SystemMonitor::class)->fetch($this->endpoint(), ['page' => $this->getTablePage(), 'page_size' => 50], fresh: true);
        }
        $this->clearApiRecords();
    }

    public function updatedTableSearch(): void
    {
        // Search applies to the fetched log page, not all logs on the server.
        $this->flushCachedTableRecords();
    }

    public function setPage($page, $pageName = 'page')
    {
        $this->paginators[$pageName] = max(1, min((int) $page, $this->lastAccessiblePage, 400));
        $this->clearApiRecords();
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        if ($this->cachedTableRecords !== null) {
            return $this->cachedTableRecords;
        }
        $logs = $this->currentPayload()['logs'] ?? [];
        $logs = is_array($logs) ? $logs : [];
        $page = $this->getTablePage();
        $hasMore = count($logs) === 50 && $page < 400;
        $this->lastAccessiblePage = $hasMore ? max($this->lastAccessiblePage, $page + 1) : $page;
        $search = trim((string) $this->tableSearch);
        $rows = [];
        foreach ($logs as $index => $line) {
            if (! is_string($line) || ($search !== '' && mb_stripos($line, $search) === false)) {
                continue;
            }
            $number = ($page - 1) * 50 + $index + 1;
            $rows[] = MonitorRecord::fromApi(['number' => $number, 'line' => $line], (string) $number);
        }

        // The endpoint does not return a total. Native simple pagination avoids inventing one.
        return $this->cachedTableRecords = (new SimplePaginator(
            new Collection($rows), 50, $page, ['pageName' => $this->getTablePaginationPageName()],
        ))->hasMorePagesWhen($hasMore);
    }

    public function getAllTableRecordsCount(): int
    {
        return $this->getTableRecords()->count();
    }

    private function copyHandler(): string
    {
        $text = Js::from(collect($this->getTableRecords()->items())->pluck('line')->implode("\n"))->toHtml();
        $success = Js::from(__('monitoring.logs.copied'))->toHtml();
        $failure = Js::from(__('monitoring.logs.copy_failed'))->toHtml();

        // Keep copying available on the company's HTTP intranet, without custom UI/CSS.
        return <<<JS
        (async () => {
            const text = {$text};
            let copied = false;
            try {
                if (window.isSecureContext && navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                    copied = true;
                }
            } catch (_) {}
            if (!copied) {
                const field = document.createElement('textarea');
                const active = document.activeElement;
                field.value = text;
                field.style.position = 'fixed';
                field.style.opacity = '0';
                field.setAttribute('aria-hidden', 'true');
                (\$el.closest('[role="dialog"]') ?? document.body).appendChild(field);
                field.focus(); field.select();
                try { copied = document.execCommand('copy'); } catch (_) {}
                field.remove(); active?.focus();
            }
            new FilamentNotification().title(copied ? {$success} : {$failure}).status(copied ? 'success' : 'warning').send();
        })()
        JS;
    }
}
