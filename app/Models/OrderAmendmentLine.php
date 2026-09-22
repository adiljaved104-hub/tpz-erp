<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrderAmendmentLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order amendment lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Order amendment lines are immutable.'));
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(OrderAmendment::class, 'order_amendment_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
