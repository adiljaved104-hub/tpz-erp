<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use LogicException;

class QuotationSourcingPosting extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['purchase_unit_cost', 'total_cost', 'source_note'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'purchase_unit_cost' => 'decimal:4', 'total_cost' => 'decimal:4', 'posted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Quotation sourcing postings are immutable.'));
        static::deleting(fn () => throw new LogicException('Quotation sourcing postings are immutable.'));
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
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
}
