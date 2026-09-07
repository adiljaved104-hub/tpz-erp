<?php

namespace App\Observers;

use App\Exceptions\ImmutableInventoryRecordException;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\ProductInventory;
use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\CriticalAlertRecipientResolver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ProductInventoryObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly CriticalAlertRecipientResolver $recipients, private readonly CriticalAlertDispatcher $alerts) {}

    public function updated(ProductInventory $inventory): void
    {
        if (! $inventory->wasChanged(['available_quantity', 'reserved_quantity'])) {
            return;
        }

        $before = (int) $inventory->getOriginal('available_quantity') - (int) $inventory->getOriginal('reserved_quantity');
        $after = $inventory->sellableQuantity();
        $type = null;
        $title = null;

        if ($before > 0 && $after <= 0) {
            $type = 'inventory.out_of_stock';
            $title = 'Product Out of Stock';
        } elseif ($before > DashboardInventoryIntelligenceService::LOW_STOCK_THRESHOLD && $after <= DashboardInventoryIntelligenceService::LOW_STOCK_THRESHOLD) {
            $type = 'inventory.low_stock';
            $title = 'Product Reached Low Stock';
        }

        if ($type === null) {
            return;
        }

        $inventory->loadMissing(['product', 'warehouse']);
        $reference = $inventory->product->sku;
        foreach ($this->recipients->inventory($inventory, $type) as $recipient) {
            $this->alerts->send($recipient, $type, $inventory->id.':'.$inventory->updated_at?->toJSON(), [
                'category' => 'inventory', 'event' => $type, 'title' => $title,
                'message' => $reference.' has '.$after.' sellable unit(s) at '.$inventory->warehouse->name.'.',
                'reference' => $reference, 'status' => $after === 0 ? 'Out of Stock' : 'Low Stock',
                'target_type' => 'product_inventory', 'target_id' => $inventory->id,
            ], $title.' — '.$reference, ProductInventoryResource::getUrl('view', ['record' => $inventory]));
        }
    }

    public function deleting(ProductInventory $inventory): never
    {
        throw new ImmutableInventoryRecordException('Product Inventory balances cannot be deleted.');
    }
}
