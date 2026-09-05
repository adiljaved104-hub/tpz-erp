<?php

namespace App\Models;

use Database\Factories\PurchaseItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'integer',
            'received_quantity' => 'integer',
            'rejected_quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'line_discount_total' => 'decimal:2',
            'inventory_unit_cost' => 'decimal:4',
            'vat_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_net' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function outstandingQuantity(): int
    {
        return $this->ordered_quantity - $this->received_quantity;
    }
}
