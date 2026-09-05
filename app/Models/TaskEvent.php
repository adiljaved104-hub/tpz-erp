<?php

namespace App\Models;

use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new TaskException('Task timeline events are immutable.'));
        static::deleting(fn () => throw new TaskException('Task timeline events are immutable.'));
    }

    protected function casts(): array
    {
        return ['event_type' => TaskEventType::class, 'from_status' => TaskStatus::class, 'to_status' => TaskStatus::class, 'old_values' => 'array', 'new_values' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
