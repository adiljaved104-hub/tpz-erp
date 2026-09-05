<?php

namespace App\Models;

use App\Enums\MarketplaceReturnRemovalStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceReturnRemoval extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => MarketplaceReturnRemovalStatus::class, 'requested_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Marketplace Removals cannot be deleted.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRemovalItem::class);
    }

    public function displayItems(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRemovalItem::class)
            ->select([
                'id', 'marketplace_return_removal_id', 'product_id', 'source_stock_type',
                'quantity', 'dispatched_quantity', 'received_quantity',
            ])
            ->with('product:id,sku,name');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRemovalEvent::class);
    }
}
