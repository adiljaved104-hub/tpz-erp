<?php

namespace App\Filament\Resources\Complaints\Widgets;

use App\Enums\ComplaintStatus;
use App\Filament\Resources\Complaints\ComplaintResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ComplaintStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return ComplaintResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = ComplaintResource::getEloquentQuery();
        $summary = DB::query()->fromSub(
            (clone $query)->select(['complaints.id', 'complaints.status']),
            'scoped_complaints',
        )->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS open_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS progress_count, '
            .'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) AS waiting_count, '
            .'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS resolved_count',
            [ComplaintStatus::Open->value, ComplaintStatus::InProgress->value, ComplaintStatus::WaitingForCustomer->value, ComplaintStatus::WaitingForMarketplace->value, ComplaintStatus::WaitingForInternalTeam->value, ComplaintStatus::Resolved->value, ComplaintStatus::Closed->value],
        )->first();

        return [
            Stat::make('Open', (int) $summary->open_count)->color('warning'),
            Stat::make('In Progress', (int) $summary->progress_count)->color('info'),
            Stat::make('Awaiting Response', (int) $summary->waiting_count)->color('warning'),
            Stat::make('Resolved', (int) $summary->resolved_count)->color('success'),
        ];
    }
}
