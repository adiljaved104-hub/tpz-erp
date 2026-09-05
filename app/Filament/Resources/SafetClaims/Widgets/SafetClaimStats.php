<?php

namespace App\Filament\Resources\SafetClaims\Widgets;

use App\Enums\SafetClaimStatus;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class SafetClaimStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return SafetClaimResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = SafetClaimResource::getEloquentQuery();
        $summary = DB::query()->fromSub(
            (clone $query)->select(['safet_claims.id', 'safet_claims.status']),
            'scoped_claims',
        )->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS filing_count, '
            .'SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS pending_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS approved_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS paid_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS rejected_count',
            [SafetClaimStatus::NeedsFiling->value, SafetClaimStatus::Filed->value, SafetClaimStatus::InReview->value, SafetClaimStatus::Approved->value, SafetClaimStatus::Paid->value, SafetClaimStatus::Rejected->value],
        )->first();

        return [
            Stat::make('Needs Filing', (int) $summary->filing_count)->color('warning'),
            Stat::make('Pending Review', (int) $summary->pending_count)->color('warning'),
            Stat::make('Approved', (int) $summary->approved_count)->color('success'),
            Stat::make('Paid', (int) $summary->paid_count)->color('success'),
            Stat::make('Rejected', (int) $summary->rejected_count)->color('danger'),
        ];
    }
}
