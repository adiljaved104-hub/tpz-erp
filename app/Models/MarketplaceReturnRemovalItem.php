<?php

namespace App\Models;

use App\Enums\MarketplaceRemovalSourceStockType;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceReturnRemovalItem extends Model
{
    protected $guarded = [];

    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return ['source_stock_type' => MarketplaceRemovalSourceStockType::class, 'quantity' => 'integer', 'dispatched_quantity' => 'integer', 'received_quantity' => 'integer', 'unit_cost' => 'decimal:4', 'carried_value' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Marketplace Removal Items cannot be deleted.'));
    }

    public function removal(): BelongsTo
    {
        return $this->belongsTo(MarketplaceReturnRemoval::class, 'marketplace_return_removal_id');
    }

    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceInventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'source_product_inventory_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CustomerReturnInspection::class);
    }
}
