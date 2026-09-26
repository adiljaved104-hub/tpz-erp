<?php

namespace App\Observers;

use App\Exceptions\ImmutableInventoryRecordException;
use App\Models\ProductInventory;
use App\Services\Notifications\StockAlertIncidentService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ProductInventoryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly StockAlertIncidentService $incidents) {}

    public function updated(ProductInventory $inventory): void
    {
        if (! $inventory->wasChanged(['available_quantity', 'reserved_quantity'])) {
            return;
        }

        $this->incidents->reconcile($inventory);
    }

    public function deleting(ProductInventory $inventory): never
    {
        throw new ImmutableInventoryRecordException('Product Inventory balances cannot be deleted.');
    }
}
