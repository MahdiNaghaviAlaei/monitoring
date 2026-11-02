<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ContainerHistoryChart;
use App\Filament\Widgets\ContainerMetrics;
use App\Filament\Widgets\ImageInventory;
use App\Filament\Widgets\ServiceStats;
use Filament\Pages\Dashboard;

class ServicesDashboard extends Dashboard
{
    public function getExtraBodyAttributes(): array
    {
        return [...parent::getExtraBodyAttributes(), 'data-monitoring-dashboard' => 'services'];
    }

    protected static string $routePath = '/services';

    public static function getNavigationLabel(): string
    {
        return __('monitoring.pages.services');
    }

    public function getTitle(): string
    {
        return __('monitoring.pages.services');
    }

    protected static ?string $navigationIcon = 'heroicon-o-cube-transparent';

    protected static ?int $navigationSort = 2;

    public function getWidgets(): array
    {
        return [ServiceStats::class, ContainerMetrics::class, ContainerHistoryChart::class, ImageInventory::class];
    }
}
