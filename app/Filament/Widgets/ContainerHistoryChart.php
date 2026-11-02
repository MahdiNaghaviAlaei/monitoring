<?php

namespace App\Filament\Widgets;

use App\Filament\Forms\JalaliDatePicker;
use App\Services\SystemMonitor;
use App\Support\JalaliDate;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\ChartWidget;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ContainerHistoryChart extends ChartWidget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    // Filament's chart template with native actions added to its headerEnd slot.
    protected static string $view = 'filament.widgets.container-history-chart';

    public function rangeActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            ...array_map(fn (string $preset) => Action::make($preset)
                ->label(__('monitoring.history.presets.'.$preset))
                ->action(fn () => $this->setDatePreset($preset)),
                ['today', 'yesterday', 'last_week', 'last_month', 'last_five_days']),
            $this->customRangeAction(),
        ])->label(fn () => $this->getRangeButtonLabel())
            ->icon('heroicon-o-calendar-days')->button()->color('gray')
            // Opening this Alpine dropdown needs no server request. Filament's
            // default loading attribute otherwise disables it during chart polling.
            ->extraAttributes([
                'wire:loading.attr' => null,
                'data-monitoring-range-trigger' => true,
                'class' => 'cursor-pointer',
            ])
            ->livewire($this);
    }

    public function customRangeAction(): Action
    {
        return Action::make('customRange')->label(__('monitoring.history.custom_range'))
            ->modalHeading(__('monitoring.history.custom_range'))->modalWidth('md')
            ->modalSubmitActionLabel(__('monitoring.history.apply_range'))
            ->fillForm(fn () => ['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo])
            ->form([
                JalaliDatePicker::make('dateFrom')->label(__('monitoring.history.date_from')),
                JalaliDatePicker::make('dateTo')->label(__('monitoring.history.date_to')),
            ])
            ->action(function (array $data): void {
                $from = JalaliDate::toGregorian($data['dateFrom']);
                $to = JalaliDate::toGregorian($data['dateTo']);
                if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= 366) {
                    throw ValidationException::withMessages([
                        'mountedActionsData.0.dateTo' => __($from > $to ? 'monitoring.history.date_order' : 'monitoring.history.date_range_limit'),
                    ]);
                }
                $this->selectDateRange($data['dateFrom'], $data['dateTo']);
            });
    }

    public function loadMoreAction(): Action
    {
        return Action::make('loadMore')->label(__('monitoring.history.load_more'))
            ->visible(fn () => $this->canLoadMoreHistory())->color('gray')
            ->action(fn () => $this->loadMoreHistory());
    }

    public function booted(): void
    {
        foreach ($this->rangeActionGroup()->getFlatActions() as $action) {
            $this->cacheAction($action);
        }
    }

    protected static bool $isLazy = false;

    public string $dateFrom = '';

    public string $dateTo = '';

    #[Locked]
    public string $appliedFrom = '';

    #[Locked]
    public string $appliedTo = '';

    #[Locked]
    public ?string $activePreset = 'last_five_days';

    #[Locked]
    public int $historyPageLimit = 100;

    protected array $historyResult = [];

    private ?array $serviceOptions = null;

    public function mount(): void
    {
        $this->resetDateRange();
        parent::mount();
    }

    public function resetDateRange(): void
    {
        $this->setDatePreset('last_five_days');
    }

    public function setDatePreset(string $preset): void
    {
        if (! in_array($preset, ['today', 'yesterday', 'last_week', 'last_month', 'last_five_days'], true)) {
            return;
        }
        $today = now()->timezone('Asia/Tehran')->startOfDay();
        $days = match ($preset) {
            'today', 'yesterday' => 1,
            'last_week' => 7,
            'last_month' => 30,
            default => 5,
        };
        $end = $preset === 'yesterday' ? $today->copy()->subDay() : $today;
        $this->appliedTo = $end->format('Y-m-d');
        $this->appliedFrom = $end->copy()->subDays($days - 1)->format('Y-m-d');
        $this->dateFrom = JalaliDate::fromGregorian($this->appliedFrom);
        $this->dateTo = JalaliDate::fromGregorian($this->appliedTo);
        $this->activePreset = $preset;
        $this->resetValidation();
        $this->resetHistory();
        $this->dispatch('monitor-history-range-applied', widgetId: $this->getId());
    }

    public function selectDateRange(string $from, string $to): void
    {
        $this->dateFrom = $from;
        $this->dateTo = $to;
        $this->applyDateRange();
    }

    public function applyDateRange(): void
    {
        $dateRule = function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || JalaliDate::toGregorian($value) === null) {
                $fail(__('monitoring.history.date_invalid'));
            }
        };
        $this->validate([
            'dateFrom' => ['bail', 'required', 'string', 'max:20', $dateRule],
            'dateTo' => ['bail', 'required', 'string', 'max:20', $dateRule],
        ], [
            'required' => __('monitoring.history.date_required'),
            'string' => __('monitoring.history.date_invalid'),
            'max' => __('monitoring.history.date_invalid'),
        ]);

        $from = JalaliDate::toGregorian($this->dateFrom);
        $to = JalaliDate::toGregorian($this->dateTo);
        if ($from > $to) {
            throw ValidationException::withMessages(['dateTo' => __('monitoring.history.date_order')]);
        }
        if (CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) >= 366) {
            throw ValidationException::withMessages(['dateTo' => __('monitoring.history.date_range_limit')]);
        }

        $this->appliedFrom = $from;
        $this->appliedTo = $to;
        $this->dateFrom = JalaliDate::fromGregorian($from);
        $this->dateTo = JalaliDate::fromGregorian($to);
        $this->activePreset = null;
        $this->resetHistory();
        $this->dispatch('monitor-history-range-applied', widgetId: $this->getId());
    }

    public function updatedFilter(): void
    {
        $this->resetHistory();
    }

    private function resetHistory(): void
    {
        $this->historyPageLimit = 100;
        $this->cachedData = null;
        $this->historyResult = [];
    }

    public function loadMoreHistory(): void
    {
        if ($this->historyPageLimit < 1000) {
            $this->historyPageLimit += 100;
            $this->cachedData = null;
        }
    }

    public function canLoadMoreHistory(): bool
    {
        $this->getCachedData();

        return isset($this->historyResult['complete'])
            && ! $this->historyResult['complete']
            && ! $this->historyResult['failed']
            && $this->historyPageLimit < 1000;
    }

    public function getActiveRangeLabel(): string
    {
        return __('monitoring.history.active_range', [
            'from' => JalaliDate::fromGregorian($this->appliedFrom),
            'to' => JalaliDate::fromGregorian($this->appliedTo),
        ]);
    }

    public function isHourly(): bool
    {
        return $this->appliedFrom === $this->appliedTo;
    }

    public function getRangeButtonLabel(): string
    {
        if ($this->activePreset !== null) {
            return __('monitoring.history.presets.'.$this->activePreset);
        }

        return $this->isHourly()
            ? JalaliDate::fromGregorian($this->appliedFrom)
            : __('monitoring.history.custom_range');
    }

    public function getHeading(): string
    {
        return __('monitoring.history.container_heading');
    }

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '320px';

    protected static ?array $options = ['scales' => ['y' => ['beginAtZero' => true]], 'spanGaps' => false];

    protected ?string $historyDescription = null;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        if ($this->serviceOptions !== null) {
            return $this->serviceOptions;
        }

        $data = app(SystemMonitor::class)->fetch('/api/containers', ['all' => 'true', 'page' => 1, 'page_size' => 500]);
        $options = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['name'] ?? null)) {
                $options[$item['id']] = $item['name'];
            }
        }

        return $this->serviceOptions = $options;
    }

    protected function getData(): array
    {
        $options = $this->getFilters();
        $id = $this->filter ?? array_key_first($options);
        if ($id === null || ! array_key_exists($id, $options)) {
            $this->historyDescription = __('monitoring.history.services_unavailable');

            return $this->aggregatedChartData([]);
        }

        $this->historyResult = app(SystemMonitor::class)->containerHistory($id, $this->appliedFrom, $this->appliedTo, $this->historyPageLimit);
        $rows = $this->historyResult['rows'];
        $this->historyDescription = $this->historyResult['failed']
            ? __('monitoring.history.range_unavailable')
            : (! $this->historyResult['complete']
                ? __('monitoring.history.range_incomplete', ['pages' => $this->historyResult['pages']])
                : ($rows === []
                    ? __('monitoring.history.range_empty')
                    : __($this->isHourly() ? 'monitoring.history.container_hourly_description' : 'monitoring.history.container_description', ['name' => $options[$id], 'samples' => number_format($this->historyResult['samples'])])));
        if (! $this->historyResult['complete'] && $this->historyPageLimit >= 1000) {
            $this->historyDescription .= ' '.__('monitoring.history.range_scan_limit');
        }
        if (count($options) === 500) {
            $this->historyDescription .= __('monitoring.history.selection_limit');
        }

        return $this->aggregatedChartData($rows);
    }

    private function aggregatedChartData(array $rows): array
    {
        $byBucket = array_column($rows, null, 'date');
        $labels = [];
        $cpu = [];
        $memory = [];
        $hourly = $this->isHourly();
        $end = CarbonImmutable::parse($this->appliedTo, 'UTC')->endOfDay();
        for ($point = CarbonImmutable::parse($this->appliedFrom, 'UTC'); $point <= $end; $point = $hourly ? $point->addHour() : $point->addDay()) {
            $key = $point->format($hourly ? 'Y-m-d H:00:00' : 'Y-m-d');
            $labels[] = $hourly ? $point->format('H:00') : JalaliDate::fromGregorian($key);
            $cpu[] = $byBucket[$key]['cpu_percent'] ?? null;
            $memory[] = $byBucket[$key]['mem_percent'] ?? null;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => __($hourly ? 'monitoring.history.hourly_cpu' : 'monitoring.history.daily_cpu'), 'data' => $cpu, 'borderColor' => '#f59e0b', 'backgroundColor' => '#f59e0b', 'pointRadius' => 3],
                ['label' => __($hourly ? 'monitoring.history.hourly_memory' : 'monitoring.history.daily_memory'), 'data' => $memory, 'borderColor' => '#3b82f6', 'backgroundColor' => '#3b82f6', 'pointRadius' => 3],
            ],
        ];
    }

    public function getDescription(): ?string
    {
        $this->getCachedData();

        return $this->getActiveRangeLabel().' · '.$this->historyDescription;
    }
}
