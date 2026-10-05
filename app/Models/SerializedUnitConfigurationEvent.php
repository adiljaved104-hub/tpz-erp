<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerializedUnitConfigurationEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_configuration' => 'array',
            'after_configuration' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Serialized unit configuration events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Serialized unit configuration events cannot be deleted.'));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
