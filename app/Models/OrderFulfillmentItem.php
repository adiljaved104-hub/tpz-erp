<?php

namespace App\Models;

use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class OrderFulfillmentItem extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'inventory_unit_cost' => 'decimal:4',
            'cogs_total' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order Fulfilment Items are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order Fulfilment Items are immutable.'));
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'order_fulfillment_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function movement(): MorphOne
    {
        return $this->morphOne(StockMovement::class, 'source');
    }

    public function customerReturnItems(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class);
    }

    public function upgradeExecution(): HasOne
    {
        return $this->hasOne(OrderUpgradeExecution::class);
    }

    public function effectiveCogsTotal(): string
    {
        return (string) ($this->upgradeExecution?->final_configured_cogs ?? $this->cogs_total);
    }
}
