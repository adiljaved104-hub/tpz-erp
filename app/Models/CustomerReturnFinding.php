<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReturnFinding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return findings are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return findings are immutable.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(CustomerReturnEvidence::class, 'finding_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
