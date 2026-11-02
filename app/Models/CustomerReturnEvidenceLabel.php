<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnEvidenceLabel extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Evidence labels are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Evidence labels are immutable.'));
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnEvidence::class, 'customer_return_evidence_id');
    }
}
