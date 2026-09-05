<?php

namespace App\Models;

use App\Enums\SafetClaimStatus;
use App\Exceptions\SafetClaimException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetClaimStatusEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => SafetClaimStatus::class, 'to_status' => SafetClaimStatus::class, 'changed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new SafetClaimException('Claim timeline is immutable.'));
        static::deleting(fn (): never => throw new SafetClaimException('Claim timeline is immutable.'));
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(SafetClaim::class, 'safet_claim_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
