<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxInvoicePaymentEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_received_aed' => 'decimal:2',
            'balance_due_aed' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice payment history is append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice payment history is append-only.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'tax_invoice_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
