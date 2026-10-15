<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcRecordedStockIntake extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purchase_receipt_item_id' => 'integer',
            'product_id' => 'integer',
            'warehouse_id' => 'integer',
            'allocation_account_id' => 'integer',
            'accepted_quantity' => 'integer',
            'started_quantity' => 'integer',
            'released_quantity' => 'integer',
        ];
    }

    public function scopeAwaitingStart(Builder $query): Builder
    {
        return $query->whereColumn('started_quantity', '<', 'accepted_quantity');
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function allocationAccount(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'allocation_account_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'recorded_stock_intake_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(QcRecordedStockIntakeRelease::class, 'recorded_stock_intake_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(QcRecordedStockIntakeAdjustment::class, 'recorded_stock_intake_id');
    }
}
