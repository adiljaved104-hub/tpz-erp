<?php

namespace App\Filament\Widgets;

use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PendingPurchaseReceivingStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::View);
    }

    protected function getStats(): array
    {
        $query = PurchaseResource::getEloquentQuery();
        $purchaseIds = (clone $query)->select('purchases.id');
        $summary = DB::query()->fromSub(
            (clone $query)->select(['purchases.id', 'purchases.status']),
            'scoped_purchases',
        )->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS approved_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS partial_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS received_count',
            [PurchaseStatus::Draft->value, PurchaseStatus::Approved->value, PurchaseStatus::PartiallyReceived->value, PurchaseStatus::FullyReceived->value],
        )->first();
        $outstanding = PurchaseItem::query()->whereIn('purchase_id', $purchaseIds)
            ->selectRaw('COALESCE(SUM(ordered_quantity - received_quantity), 0) as aggregate')
            ->value('aggregate');

        return [
            Stat::make('Draft', (int) $summary->draft_count)->color('gray'),
            Stat::make('Approved Awaiting Receipt', (int) $summary->approved_count)->color('warning'),
            Stat::make('Partially Received', (int) $summary->partial_count)->color('warning'),
            Stat::make('Fully Received', (int) $summary->received_count)->color('success'),
            Stat::make('Outstanding Units', (int) $outstanding),
        ];
    }
}
