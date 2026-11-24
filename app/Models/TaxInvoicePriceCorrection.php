<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxInvoicePriceCorrection extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice correction history is append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice correction history is append-only.'));
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'original_invoice_id');
    }

    public function replacementInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'replacement_invoice_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
