<?php

namespace App\Filament\Resources\QcInspections\Widgets;

use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QcProgress extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $base = app(QcInspectionService::class)->visible(auth()->user());
        $today = app(BusinessTimezone::class)->date(now())->startOfDay();

        return [Stat::make('Pending QC', (clone $base)->where('status', 'pending')->count()), Stat::make('In Progress', (clone $base)->where('status', 'in_progress')->count()), Stat::make('Rework Required', (clone $base)->where('status', 'rework_required')->count()), Stat::make('Completed Today', (clone $base)->where('completed_at', '>=', $today->setTimezone('UTC'))->where('completed_at', '<', $today->addDay()->setTimezone('UTC'))->count())];
    }
}
