<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnExceptionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return exception events are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return exception events are immutable.'));
    }

    public function exceptionCase(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnExceptionCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
