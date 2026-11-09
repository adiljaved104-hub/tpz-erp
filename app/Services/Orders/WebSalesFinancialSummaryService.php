<?php

namespace App\Services\Orders;

use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;

final class WebSalesFinancialSummaryService
{
    /** @return array{cogs:string, status:string, gross_profit:?string} */
    public function summarize(Order $order): array
    {
        $order->loadMissing([
            'items.product.inventories',
            'items.manualSourcingLine',
            'items.fulfillmentItem.upgradeExecution',
        ]);

        $cogs = '0.0000';
        $hasPending = false;
        $hasEstimate = false;

        foreach ($order->items as $item) {
            $cost = $this->lineCost($order, $item);
            if ($cost === null) {
                $hasPending = true;
                continue;
            }

            $cogs = bcadd($cogs, $cost, 4);
            $hasEstimate = $hasEstimate || $item->fulfillmentItem === null;
        }

        $status = $hasPending ? 'pending' : ($hasEstimate ? 'estimated' : 'actual');

        return [
            'cogs' => $cogs,
            'status' => $status,
            'gross_profit' => $hasPending ? null : bcsub((string) $order->grand_total, $cogs, 2),
        ];
    }

    public function canViewCost(User $user): bool
    {
        return app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::ViewCost);
    }

    public function canViewProfit(User $user): bool
    {
        return app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::ViewGrossProfit);
    }

    private function lineCost(Order $order, OrderItem $item): ?string
    {
        if ($item->fulfillmentItem !== null) {
            return (string) $item->fulfillmentItem->effectiveCogsTotal();
        }

        if ($item->manualSourcingLine?->actual_unit_cost !== null) {
            return bcmul((string) $item->manualSourcingLine->actual_unit_cost, (string) $item->ordered_quantity, 4);
        }

        $average = $item->product?->inventories
            ->firstWhere('warehouse_id', $order->warehouse_id)?->average_cost;

        return $average === null ? null : bcmul((string) $average, (string) $item->ordered_quantity, 4);
    }
}
