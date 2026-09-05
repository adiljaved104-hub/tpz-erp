<?php

namespace App\Models;

use App\Enums\CustomerReturnSource;
use App\Enums\CustomerReturnStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturn extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => CustomerReturnStatus::class, 'return_source' => CustomerReturnSource::class,
            'reported_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Customer Returns cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function fulfillmentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'fulfillment_warehouse_id');
    }

    public function receivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'receiving_warehouse_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class);
    }

    public function displayItems(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class)->select(['id', 'customer_return_id', 'product_id', 'sku_snapshot', 'product_name_snapshot', 'return_quantity', 'return_reason']);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(CustomerReturnStatusEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(CustomerReturnRefund::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(SafetClaim::class);
    }

    public function warrantyRepairs(): HasMany
    {
        return $this->hasMany(WarrantyRepair::class);
    }
}
