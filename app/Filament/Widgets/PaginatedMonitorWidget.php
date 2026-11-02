<?php

namespace App\Filament\Widgets;

use App\Services\SystemMonitor;
use App\Support\MonitorRecord;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class PaginatedMonitorWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    private array $apiPayloads = [];

    abstract protected function endpoint(): string;

    public function table(Table $table): Table
    {
        // Filament 3 needs model metadata. This builder is never executed:
        // records and action resolution below use API responses exclusively.
        return $table->query(MonitorRecord::query())
            ->queryStringIdentifier(class_basename(static::class))
            ->paginationPageOptions([10])->poll('30s');
    }

    protected function payload(): ?array
    {
        return app(SystemMonitor::class)->fetch($this->endpoint(), [
            'page' => $this->getTablePage(), 'page_size' => 10,
        ]);
    }

    protected function currentPayload(): ?array
    {
        $page = $this->getTablePage();
        if (! array_key_exists($page, $this->apiPayloads)) {
            $this->apiPayloads[$page] = $this->payload();
        }

        return $this->apiPayloads[$page];
    }

    protected function clearApiRecords(): void
    {
        $this->apiPayloads = [];
        $this->flushCachedTableRecords();
    }

    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        if ($this->cachedTableRecords !== null) {
            return $this->cachedTableRecords;
        }
        $data = $this->currentPayload();
        $rows = is_array($data['items'] ?? null) ? array_values(array_filter($data['items'], 'is_array')) : [];
        $records = new Collection(array_map(
            fn (array $row, int $index) => MonitorRecord::fromApi($row, (string) ($row['id'] ?? ($this->getTablePage().'-'.$index))),
            $rows, array_keys($rows),
        ));

        return $this->cachedTableRecords = new LengthAwarePaginator(
            $records, max(0, (int) ($data['total_items'] ?? count($rows))), 10, $this->getTablePage(),
            ['pageName' => $this->getTablePaginationPageName()],
        );
    }

    protected function resolveTableRecord(?string $key): ?Model
    {
        return $key === null ? null : collect($this->getTableRecords()->items())
            ->first(fn (Model $record) => (string) $record->getKey() === $key);
    }

    public function getAllTableRecordsCount(): int
    {
        return $this->getTableRecords()->total();
    }
}
