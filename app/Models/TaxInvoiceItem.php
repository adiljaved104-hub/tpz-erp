<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxInvoiceItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_price_including_vat' => 'decimal:2', 'subtotal_excluding_vat' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_including_vat' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued Invoice items are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued Invoice items cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'tax_invoice_id');
    }
}
