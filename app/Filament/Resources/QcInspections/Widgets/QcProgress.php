<?php

namespace App\Filament\Resources\QcInspections\Widgets;

use App\Enums\QcPermission;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcTransferDemandService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QcProgress extends StatsOverviewWidget
{
    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    protected function getStats(): array
    {
        $base = app(QcInspectionService::class)->visible(auth()->user());
        $today = app(BusinessTimezone::class)->date(now())->startOfDay();
        $user = auth()->user();
        $orderUnitsNeeded = 0;

        if ($user
            && app(QcAuthorization::class)->allows($user, QcPermission::View)
            && app(QcAuthorization::class)->allows($user, QcPermission::Start)) {
            $orderUnitsNeeded = app(QcOrderDemandService::class)->calculateForQcQueue($user)['units_needed'];
        }

        $transferSummary = $user
            ? app(QcTransferDemandService::class)->summary($user)
            : ['units_needed' => 0, 'transfers_affected' => 0, 'marketplace_transfers' => 0];

        return [
            Stat::make('Pending QC', (clone $base)->where('status', 'pending')->count()),
            Stat::make('In Progress', (clone $base)->where('status', 'in_progress')->count()),
            Stat::make('Rework Required', (clone $base)->where('status', 'rework_required')->count()),
            Stat::make('Completed Today', (clone $base)->where('completed_at', '>=', $today->setTimezone('UTC'))->where('completed_at', '<', $today->addDay()->setTimezone('UTC'))->count()),
            Stat::make('QC Needed for Orders', $orderUnitsNeeded)
                ->description('Units still needing QC for customer orders')
                ->icon('heroicon-o-shopping-bag'),
            Stat::make('QC Needed for Transfers', $transferSummary['units_needed'])
                ->description('Units still needing QC for marketplace transfers')
                ->icon('heroicon-o-arrow-path-rounded-square'),
            Stat::make('Marketplace Transfers', $transferSummary['marketplace_transfers'])
                ->description('FBA / marketplace transfers waiting for QC')
                ->icon('heroicon-o-archive-box-arrow-down'),
        ];
    }
}
