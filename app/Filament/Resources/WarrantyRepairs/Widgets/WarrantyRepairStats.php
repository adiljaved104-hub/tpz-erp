<?php

namespace App\Filament\Resources\WarrantyRepairs\Widgets;

use App\Enums\WarrantyRepairStatus;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WarrantyRepairStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return WarrantyRepairResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = WarrantyRepairResource::getEloquentQuery();
        $summary = DB::query()->fromSub(
            (clone $query)->select(['warranty_repairs.id', 'warranty_repairs.status']),
            'scoped_repairs',
        )->selectRaw(
            'SUM(CASE WHEN status NOT IN (?, ?) THEN 1 ELSE 0 END) AS open_count, '
            .'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS repair_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS parts_count, '
            .'SUM(CASE WHEN status IN (?, ?, ?, ?) THEN 1 ELSE 0 END) AS ready_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS dispatched_count',
            [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value, WarrantyRepairStatus::SendToTechnician->value, WarrantyRepairStatus::InRepair->value, WarrantyRepairStatus::WaitingForParts->value, WarrantyRepairStatus::RepairCompleted->value, WarrantyRepairStatus::ReceivedBack->value, WarrantyRepairStatus::QcPending->value, WarrantyRepairStatus::ReadyToReturn->value, WarrantyRepairStatus::DispatchedBack->value],
        )->first();

        return [
            Stat::make('Open Cases', (int) $summary->open_count),
            Stat::make('In Repair', (int) $summary->repair_count)->color('warning'),
            Stat::make('Waiting for Parts', (int) $summary->parts_count)->color('warning'),
            Stat::make('Ready / Completed', (int) $summary->ready_count)->color('success'),
            Stat::make('Dispatched', (int) $summary->dispatched_count)->color('success'),
        ];
    }
}
