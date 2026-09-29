<?php

namespace App\Models;

use App\Exceptions\ImmutablePurchaseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseReceiptCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original_received_quantity' => 'integer',
            'quantity_before' => 'integer',
            'corrected_quantity' => 'integer',
            'adjustment_quantity' => 'integer',
            'inventory_unit_cost' => 'decimal:4',
            'corrected_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutablePurchaseException('Purchase Receipt corrections are immutable.'));
        static::deleting(fn (): never => throw new ImmutablePurchaseException('Purchase Receipt corrections are immutable.'));
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function receiptItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptItem::class, 'purchase_receipt_item_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function allocationLines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptCorrectionAllocationLine::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
