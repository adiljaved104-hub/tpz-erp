<?php

namespace App\Filament\Resources\InternalRepairs\Widgets;

use App\Enums\WarrantyRepairStatus;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InternalRepairStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $query = InternalRepairResource::getEloquentQuery();

        return [
            Stat::make('Open Repairs', (clone $query)->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])->count()),
            Stat::make('With Technicians', (clone $query)->whereIn('status', [WarrantyRepairStatus::SendToTechnician->value, WarrantyRepairStatus::InRepair->value, WarrantyRepairStatus::WaitingForParts->value, WarrantyRepairStatus::RepairCompleted->value])->count()),
            Stat::make('Waiting for Parts', (clone $query)->where('status', WarrantyRepairStatus::WaitingForParts->value)->count()),
            Stat::make('Ready for QC', (clone $query)->whereIn('status', [WarrantyRepairStatus::ReceivedBack->value, WarrantyRepairStatus::QcPending->value])->count()),
            Stat::make('Completed', (clone $query)->where('status', WarrantyRepairStatus::Completed->value)->count()),
        ];
    }
}
