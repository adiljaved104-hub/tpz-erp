<?php

namespace App\Models;

use App\Enums\CustomerReturnReason;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturnItem extends Model
{
    protected $guarded = [];

    protected $hidden = ['inventory_unit_cost'];

    protected function casts(): array
    {
        return ['fulfilled_quantity_snapshot' => 'integer', 'return_quantity' => 'integer',
            'inventory_unit_cost' => 'decimal:4', 'return_reason' => CustomerReturnReason::class];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Customer Return Items are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Customer Return Items are immutable.'));
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillmentItem::class, 'order_fulfillment_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CustomerReturnInspection::class);
    }

    public function marketplaceDispositions(): HasMany
    {
        return $this->hasMany(CustomerReturnMarketplaceDisposition::class);
    }

    public function removalItems(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRemovalItem::class);
    }

    public function claim(): HasOne
    {
        return $this->hasOne(SafetClaim::class);
    }

    public function companyReceivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'company_receiving_warehouse_id');
    }

    public function inspectedQuantity(): int
    {
        return (int) $this->inspections()->sum('quantity');
    }
}
