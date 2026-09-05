<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuotationEmailDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Quotation email history cannot be deleted.'));
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
