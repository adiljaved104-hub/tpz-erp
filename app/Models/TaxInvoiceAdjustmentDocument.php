<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxInvoiceAdjustmentDocument extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'seller_snapshot' => 'array',
            'customer_snapshot' => 'array',
            'lines_snapshot' => 'array',
            'net_amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'original_invoice_net' => 'decimal:2',
            'corrected_invoice_net' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued tax adjustment documents cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Issued tax adjustment documents cannot be deleted.'));
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(TaxInvoicePriceAdjustmentProposal::class, 'proposal_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'original_invoice_id');
    }

    public function accountingJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'adjustment_accounting_journal_id');
    }

    public function originalAccountingJournal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'original_accounting_journal_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
