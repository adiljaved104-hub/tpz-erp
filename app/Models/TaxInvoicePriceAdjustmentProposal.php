<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class TaxInvoicePriceAdjustmentProposal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original_line_snapshot' => 'array',
            'proposed_line_snapshot' => 'array',
            'adjustment_documents_preview' => 'array',
            'old_gross' => 'decimal:2',
            'new_gross' => 'decimal:2',
            'gross_difference' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice price adjustment proposals are append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice price adjustment proposals are append-only.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'tax_invoice_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TaxInvoiceAdjustmentDocument::class, 'proposal_id');
    }

    public function decision(): HasOne
    {
        return $this->hasOne(TaxInvoiceAdjustmentReviewDecision::class, 'proposal_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
