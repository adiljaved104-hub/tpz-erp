<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order timeline events are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order timeline events are immutable.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
