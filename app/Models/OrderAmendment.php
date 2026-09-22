<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class OrderAmendment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_started_at' => 'immutable_datetime',
            'window_expired_at' => 'immutable_datetime',
            'after_window_override' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order amendment history is immutable.'));
        static::deleting(fn () => throw new LogicException('Order amendment history is immutable.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderAmendmentLine::class);
    }
}
