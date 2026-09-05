<?php

namespace App\Models;

use App\Enums\TaskCompletionSubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskCompletionSubmission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => TaskCompletionSubmissionStatus::class, 'submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'task_assignment_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskCompletionSubmissionEvent::class);
    }
}
