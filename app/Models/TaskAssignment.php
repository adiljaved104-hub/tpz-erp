<?php

namespace App\Models;

use App\Enums\TaskAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TaskAssignmentStatus::class,
            'follow_up_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'backfilled_from_legacy' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function teamAtAssignment(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id_at_assignment');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskAssignmentEvent::class);
    }

    public function completionSubmissions(): HasMany
    {
        return $this->hasMany(TaskCompletionSubmission::class);
    }
}
