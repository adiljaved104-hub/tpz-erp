<?php

namespace App\Filament\Resources\StockTransfers\Widgets;

use App\Enums\StockTransferStatus;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StockTransferStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return StockTransferResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = StockTransferResource::getEloquentQuery();
        $summary = DB::query()->fromSub(
            (clone $query)->select(['stock_transfers.id', 'stock_transfers.status']),
            'scoped_transfers',
        )->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS transit_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS received_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS returned_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count',
            [StockTransferStatus::Draft->value, StockTransferStatus::Dispatched->value, StockTransferStatus::Received->value, StockTransferStatus::Returned->value, StockTransferStatus::Cancelled->value],
        )->first();

        return [
            Stat::make('Draft', (int) $summary->draft_count)->color('gray'),
            Stat::make('In Transit', (int) $summary->transit_count)->color('warning'),
            Stat::make('Received', (int) $summary->received_count)->color('success'),
            Stat::make('Returned', (int) $summary->returned_count)->color('warning'),
            Stat::make('Cancelled', (int) $summary->cancelled_count)->color('danger'),
        ];
    }
}
