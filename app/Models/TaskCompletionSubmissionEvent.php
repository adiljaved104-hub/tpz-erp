<?php

namespace App\Models;

use App\Exceptions\TaskException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskCompletionSubmissionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new TaskException('Task completion events are immutable.'));
        static::deleting(fn () => throw new TaskException('Task completion events are immutable.'));
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskCompletionSubmission::class, 'task_completion_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
