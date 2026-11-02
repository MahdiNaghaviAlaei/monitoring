<?php

namespace App\Filament\Widgets;

use App\Support\MonitorFormat;
use App\Support\MonitorRecord;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImageInventory extends PaginatedMonitorWidget
{
    protected function endpoint(): string
    {
        return '/api/images';
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading(__('monitoring.images.heading'))
            ->description(__('monitoring.images.description'))
            ->emptyStateHeading(fn () => __(is_array($this->currentPayload()['items'] ?? null) ? 'monitoring.images.empty' : 'monitoring.images.unavailable'))
            ->columns([
                TextColumn::make('tags')->label(__('monitoring.images.tags'))
                    ->getStateUsing(fn (MonitorRecord $record) => is_array($record->tags) ? array_values(array_filter($record->tags, 'is_string')) : [])
                    ->listWithLineBreaks()->wrap()->placeholder(__('monitoring.images.untagged')),
                TextColumn::make('id')->label(__('monitoring.images.id'))->wrap()->copyable(),
                TextColumn::make('size_mb')->label(__('monitoring.images.size'))
                    ->getStateUsing(fn (MonitorRecord $record) => MonitorFormat::number($record->size_mb, 'MB')),
            ]);
    }
}
