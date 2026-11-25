<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class TaxInvoiceAdjustmentReviewDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finance review decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Finance review decisions are append-only.'));
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(TaxInvoicePriceAdjustmentProposal::class, 'proposal_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
