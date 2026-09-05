<?php

namespace App\Policies;

use App\Enums\InventoryPermission;
use App\Models\OpeningStockEntry;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;

class OpeningStockEntryPolicy
{
    public function __construct(private readonly InventoryAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::View);
    }

    public function view(User $user, OpeningStockEntry $entry): bool
    {
        $inventory = ProductInventory::query()
            ->where('product_id', $entry->product_id)
            ->where('warehouse_id', $entry->warehouse_id)
            ->first();

        return $inventory !== null
            && $this->authorization->allows($user, InventoryPermission::View, $inventory);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, InventoryPermission::PostOpeningStock);
    }

    public function update(User $user, OpeningStockEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, OpeningStockEntry $entry): bool
    {
        return false;
    }

    public function restore(User $user, OpeningStockEntry $entry): bool
    {
        return false;
    }

    public function forceDelete(User $user, OpeningStockEntry $entry): bool
    {
        return false;
    }
}
