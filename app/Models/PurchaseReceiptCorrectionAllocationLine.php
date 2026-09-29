<?php

namespace App\Models;

use App\Exceptions\ImmutablePurchaseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptCorrectionAllocationLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutablePurchaseException('Purchase Receipt correction allocation lines are immutable.'));
        static::deleting(fn (): never => throw new ImmutablePurchaseException('Purchase Receipt correction allocation lines are immutable.'));
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptCorrection::class, 'purchase_receipt_correction_id');
    }

    public function originalLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptAllocationLine::class, 'purchase_receipt_allocation_line_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class);
    }
}
