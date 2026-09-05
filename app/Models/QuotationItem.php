<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuotationItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_price_including_vat' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:4',
            'subtotal_excluding_vat' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'total_including_vat' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            if ($item->quotation()->where('status', '!=', QuotationStatus::Draft->value)->exists()) {
                throw new LogicException('Sent Quotation items are immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
