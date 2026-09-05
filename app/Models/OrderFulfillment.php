<?php

namespace App\Models;

use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderFulfillment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['fulfilled_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order Fulfilments are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order Fulfilments are immutable.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderFulfillmentItem::class);
    }
}
