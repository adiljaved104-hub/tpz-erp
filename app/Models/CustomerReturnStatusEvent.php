<?php

namespace App\Models;

use App\Enums\CustomerReturnStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => CustomerReturnStatus::class, 'to_status' => CustomerReturnStatus::class, 'context' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return status history is immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return status history is immutable.'));
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
