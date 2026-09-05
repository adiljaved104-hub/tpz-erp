<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\OrderItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class OrderStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return OrderResource::canViewAny();
    }

    protected function getStats(): array
    {
        $query = OrderResource::getEloquentQuery();
        $orderIds = (clone $query)->select('orders.id');
        $summary = DB::query()->fromSub(
            (clone $query)->select(['orders.id', 'orders.status', 'orders.order_date']),
            'scoped_orders',
        )->selectRaw(
            'SUM(CASE WHEN DATE(order_date) = ? THEN 1 ELSE 0 END) AS today_count, '
            .'SUM(CASE WHEN status IN (?, ?, ?, ?) THEN 1 ELSE 0 END) AS pending_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS shipped_count, '
            .'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS cancelled_count',
            [today()->toDateString(), OrderStatus::PendingReview->value, OrderStatus::Confirmed->value, OrderStatus::Reserved->value, OrderStatus::Processing->value, OrderStatus::Fulfilled->value, OrderStatus::Cancelled->value],
        )->first();

        return [
            Stat::make('Orders Today', (int) $summary->today_count)->icon('heroicon-o-calendar-days'),
            Stat::make('Pending / Reserved', (int) $summary->pending_count)->color('warning'),
            Stat::make('Shipped', (int) $summary->shipped_count)->color('success'),
            Stat::make('Cancelled', (int) $summary->cancelled_count)->color('danger'),
            Stat::make('Units', (int) OrderItem::query()->whereIn('order_id', $orderIds)->sum('ordered_quantity')),
        ];
    }
}
