<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComponentRecoveryValueEvent extends Model
{
    protected $fillable = ['component_id', 'old_value', 'new_value', 'reason', 'actor_user_id', 'recorded_at'];

    protected function casts(): array
    {
        return ['old_value' => 'decimal:4', 'new_value' => 'decimal:4', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => throw new LogicException('Component recovery-value events are append-only.'));
        static::deleting(fn (): bool => throw new LogicException('Component recovery-value events are append-only.'));
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
