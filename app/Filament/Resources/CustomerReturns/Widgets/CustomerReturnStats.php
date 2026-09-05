<?php

namespace App\Filament\Resources\CustomerReturns\Widgets;

use App\Enums\CustomerReturnStatus;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class CustomerReturnStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return CustomerReturnResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = CustomerReturnResource::getEloquentQuery();
        $summary = DB::query()->fromSub(
            (clone $query)->select(['customer_returns.id', 'customer_returns.status']),
            'scoped_returns',
        )->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS qc_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count',
            [CustomerReturnStatus::Draft->value, CustomerReturnStatus::QcPending->value, CustomerReturnStatus::Completed->value, CustomerReturnStatus::Cancelled->value],
        )->first();

        return [
            Stat::make('Pending Receipt', (int) $summary->draft_count)->color('warning'),
            Stat::make('Awaiting QC', (int) $summary->qc_count)->color('warning'),
            Stat::make('Completed', (int) $summary->completed_count)->color('success'),
            Stat::make('Cancelled', (int) $summary->cancelled_count)->color('danger'),
        ];
    }
}
