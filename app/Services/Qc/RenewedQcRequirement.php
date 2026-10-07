<?php

namespace App\Services\Qc;

use App\Enums\ProductCondition;
use App\Models\Order;
use App\Models\OrderItem;

/** The single policy for deciding which order lines require unit-level QC. */
class RenewedQcRequirement
{
    public function requires(OrderItem $item): bool
    {
        $product = $item->relationLoaded('product') ? $item->getRelation('product') : $item->product;

        return $this->conditionRequires($product?->condition);
    }

    public function conditionRequires(mixed $condition): bool
    {
        return $condition === ProductCondition::Renewed || $condition === ProductCondition::Renewed->value;
    }

    public function requiredQuantity(OrderItem $item): int
    {
        return $this->requires($item) ? max(0, (int) $item->ordered_quantity) : 0;
    }

    public function orderHasRequiredLine(Order $order): bool
    {
        return $order->items()->with('product')->get()->contains(fn (OrderItem $item): bool => $this->requires($item));
    }
}
