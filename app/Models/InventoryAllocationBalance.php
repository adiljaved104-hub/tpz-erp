<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAllocationBalance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['allocated_quantity' => 'integer', 'reserved_quantity' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'account_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function availableQuantity(): int
    {
        return $this->allocated_quantity - $this->reserved_quantity;
    }
}
