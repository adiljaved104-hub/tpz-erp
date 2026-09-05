<?php

namespace App\Models;

use App\Exceptions\ImmutablePurchaseException;
use Database\Factories\PurchaseReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseReceiptItem extends Model
{
    /** @use HasFactory<PurchaseReceiptItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'accepted_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'rejected_quantity' => 'integer',
            'inventory_unit_cost' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutablePurchaseException('Goods Received Note items cannot be updated.'));
        static::deleting(fn (): never => throw new ImmutablePurchaseException('Goods Received Note items cannot be deleted.'));
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    public function valuationQuantity(): int
    {
        return $this->accepted_quantity + $this->damaged_quantity;
    }
}
