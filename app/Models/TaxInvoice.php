<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TaxInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date', 'issued_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime',
            'seller_snapshot' => 'array', 'vat_rate' => 'decimal:2', 'subtotal_excluding_vat' => 'decimal:2',
            'vat_amount' => 'decimal:2', 'grand_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (TaxInvoice $invoice): void {
            $allowed = ['status', 'voided_at', 'voided_by_user_id', 'void_reason', 'updated_at'];
            if (array_diff(array_keys($invoice->getDirty()), $allowed) !== []) {
                throw new LogicException('Issued Invoice content is immutable. Void and reissue for material corrections.');
            }
        });
        static::deleting(fn () => throw new LogicException('Tax Invoices cannot be hard-deleted.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(TaxInvoiceItem::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }
}
