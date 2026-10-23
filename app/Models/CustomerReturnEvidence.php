<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnEvidence extends Model
{
    protected $table = 'customer_return_evidence';

    protected $guarded = [];

    protected $hidden = ['original_path', 'sha256'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'duration_seconds' => 'integer', 'uploaded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return evidence is immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return evidence is immutable.'));
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnFinding::class, 'finding_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
