<?php

namespace App\Models;

use App\Enums\TaskLinkedType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new TaskException('Tasks cannot be deleted. Complete or cancel the Task instead.'));
    }

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class, 'priority' => TaskPriority::class, 'linked_type' => TaskLinkedType::class,
            'due_at' => 'immutable_datetime', 'follow_up_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }

    public function assignedTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'assigned_team_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function completionSubmissions(): HasMany
    {
        return $this->hasMany(TaskCompletionSubmission::class);
    }

    public function latestUpdate(): HasOne
    {
        return $this->hasOne(TaskEvent::class)->ofMany(['occurred_at' => 'max', 'id' => 'max'], fn (Builder $query) => $query->where('event_type', 'comment_added'));
    }

    public function hasPendingCompletion(): bool
    {
        return $this->relationLoaded('completionSubmissions')
            ? $this->completionSubmissions->contains('status.value', 'pending')
            : $this->completionSubmissions()->where('status', 'pending')->exists();
    }

    public function assignmentLabel(): string
    {
        $assignments = $this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->with('employee:id,name')->get();
        $names = $assignments->reject(fn (TaskAssignment $assignment): bool => in_array($assignment->status->value, ['removed', 'cancelled'], true))
            ->pluck('employee.name')->filter();
        if ($names->isNotEmpty()) {
            return $names->join(', ');
        }
        if ($this->assigned_team_id !== null) {
            return 'Team — '.($this->assignedTeam?->name ?? 'Unknown');
        }

        return $this->assignedEmployee?->name ?? 'Unassigned';
    }

    /** @return array{total: int, in_progress: int, awaiting_confirmation: int, confirmed: int} */
    public function assignmentProgress(): array
    {
        $assignments = ($this->relationLoaded('assignments') ? $this->assignments : $this->assignments()->get())
            ->reject(fn (TaskAssignment $assignment): bool => in_array($assignment->status->value, ['removed', 'cancelled'], true));
        $pendingAssignmentIds = ($this->relationLoaded('completionSubmissions') ? $this->completionSubmissions : $this->completionSubmissions()->get())
            ->where('status.value', 'pending')->pluck('task_assignment_id')->filter();

        return [
            'total' => $assignments->count(),
            'in_progress' => $assignments->where('status.value', 'in_progress')->count(),
            'awaiting_confirmation' => $assignments->whereIn('id', $pendingAssignmentIds)->count(),
            'confirmed' => $assignments->where('status.value', 'completed')->count(),
        ];
    }

    public function assignmentProgressLabel(): string
    {
        $progress = $this->assignmentProgress();
        if ($progress['total'] === 0) {
            return '—';
        }

        return "{$progress['confirmed']} / {$progress['total']} Confirmed"
            ." · {$progress['in_progress']} In Progress"
            ." · {$progress['awaiting_confirmation']} Awaiting";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isTerminal() && ! $this->hasPendingCompletion() && $this->due_at?->isPast() === true;
    }

    public function isDueSoon(): bool
    {
        return ! $this->status->isTerminal() && ! $this->hasPendingCompletion() && $this->due_at !== null && ! $this->due_at->isPast() && $this->due_at->lte(now()->addHours(24));
    }

    public function dueLabel(): ?string
    {
        if ($this->due_at === null) {
            return null;
        }
        if ($this->status->isTerminal()) {
            return $this->due_at->format('d M Y, h:i A');
        }
        if ($this->hasPendingCompletion()) {
            return 'Submitted for confirmation';
        }
        $seconds = now()->diffInSeconds($this->due_at, false);
        if ($seconds < 0) {
            $days = max(1, (int) ceil(abs($seconds) / 86400));

            return $days.' '.str('day')->plural($days).' overdue';
        }
        if (now()->isSameDay($this->due_at)) {
            return 'Due today';
        }
        if ($seconds < 86400) {
            $hours = max(1, (int) ceil($seconds / 3600));

            return $hours.' '.str('hour')->plural($hours).' left';
        }
        $days = max(1, (int) ceil($seconds / 86400));

        return $days.' '.str('day')->plural($days).' left';
    }
}
