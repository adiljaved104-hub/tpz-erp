<?php

namespace App\Services\Inventory;

use App\Models\ProductInventory;
use Illuminate\Support\Collection;

class InventoryReconciliationService
{
    /** @return Collection<int, array<string, mixed>> */
    public function discrepancies(): Collection
    {
        return ProductInventory::query()->get()->map(function (ProductInventory $inventory): ?array {
            $last = $inventory->movements()->latest('id')->first();

            if ($last === null) {
                return $inventory->totalOnHand() === 0 ? null : ['product_inventory_id' => $inventory->id, 'issue' => 'positive_balance_without_movement'];
            }

            if ($last->available_after !== $inventory->available_quantity
                || $last->reserved_after !== $inventory->reserved_quantity
                || $last->damaged_after !== $inventory->damaged_quantity) {
                return ['product_inventory_id' => $inventory->id, 'issue' => 'latest_movement_balance_mismatch'];
            }

            return null;
        })->filter()->values();
    }
}
