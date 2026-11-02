<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\HostHistoryChart;
use Filament\Pages\Dashboard;

class SystemDashboard extends Dashboard
{
    public static function getNavigationLabel(): string
    {
        return __('monitoring.pages.systems');
    }

    public function getTitle(): string
    {
        return __('monitoring.pages.systems');
    }

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?int $navigationSort = 1;

    public function getWidgets(): array
    {
        return [DashboardStats::class, HostHistoryChart::class];
    }
}
