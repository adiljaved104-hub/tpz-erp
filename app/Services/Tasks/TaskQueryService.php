<?php

namespace App\Services\Tasks;

use App\Enums\TaskAssignmentStatus;
use App\Enums\TaskCompletionSubmissionStatus;
use App\Enums\TaskPermission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class TaskQueryService
{
    public function __construct(private readonly TaskAuthorization $authorization) {}

    public function visible(User $user): Builder
    {
        return $this->authorization->scopeQuery(Task::query(), $user);
    }

    public function personalActive(User $user): Builder
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null || ! $this->authorization->allows($user, TaskPermission::View)) {
            return Task::query()->whereRaw('1 = 0');
        }

        return $this->visible($user)
            ->active()
            ->where(function (Builder $mine) use ($employeeId): void {
                $mine->whereHas('assignments', fn (Builder $assignment) => $assignment
                    ->where('employee_id', $employeeId)
                    ->whereIn('status', $this->activeAssignmentStatuses()))
                    ->orWhere(function (Builder $legacy) use ($employeeId): void {
                        $legacy->whereDoesntHave('assignments')
                            ->where('assigned_employee_id', $employeeId);
                    });
            })
            ->with([
                'assignments' => fn ($assignment) => $assignment
                    ->where('employee_id', $employeeId)
                    ->with('completionSubmissions'),
                'completionSubmissions' => fn ($submission) => $this->constrainPersonalSubmissions($submission, $employeeId),
            ]);
    }

    /** @return array{pending: int, in_progress: int, waiting: int, awaiting_confirmation: int, overdue: int} */
    public function personalSummary(User $user): array
    {
        $employeeId = $user->employee?->id;
        $base = $this->personalActive($user);
        if ($employeeId === null) {
            return ['pending' => 0, 'in_progress' => 0, 'waiting' => 0, 'awaiting_confirmation' => 0, 'overdue' => 0];
        }

        $pendingSubmission = fn (Builder $submission) => $this->constrainPersonalSubmissions($submission, $employeeId)
            ->where('status', TaskCompletionSubmissionStatus::Pending->value);

        return [
            'pending' => (clone $base)->where(function (Builder $query) use ($employeeId): void {
                $query->whereHas('assignments', fn (Builder $assignment) => $assignment
                    ->where('employee_id', $employeeId)
                    ->where('status', TaskAssignmentStatus::Assigned->value))
                    ->orWhere(function (Builder $legacy) use ($employeeId): void {
                        $legacy->whereDoesntHave('assignments')
                            ->where('assigned_employee_id', $employeeId)
                            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Assigned->value]);
                    });
            })->count(),
            'in_progress' => (clone $base)->whereDoesntHave('completionSubmissions', $pendingSubmission)
                ->where(fn (Builder $query) => $this->wherePersonalStatus($query, $employeeId, TaskAssignmentStatus::InProgress))->count(),
            'waiting' => (clone $base)->whereDoesntHave('completionSubmissions', $pendingSubmission)
                ->where(fn (Builder $query) => $this->wherePersonalStatus($query, $employeeId, TaskAssignmentStatus::Waiting))->count(),
            'awaiting_confirmation' => (clone $base)->whereHas('completionSubmissions', $pendingSubmission)->count(),
            'overdue' => (clone $base)->whereDoesntHave('completionSubmissions', $pendingSubmission)
                ->whereNotNull('due_at')->where('due_at', '<', now())->count(),
        ];
    }

    /** @return Collection<int, Task> */
    public function personalDashboardTasks(User $user, int $limit = 5): Collection
    {
        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return new Collection;
        }

        return $this->personalActive($user)
            ->withExists(['completionSubmissions as personal_awaiting_confirmation' => fn (Builder $submission) => $this
                ->constrainPersonalSubmissions($submission, $employeeId)
                ->where('status', TaskCompletionSubmissionStatus::Pending->value)])
            ->orderByRaw('CASE WHEN tasks.due_at IS NOT NULL AND tasks.due_at < ? AND personal_awaiting_confirmation = 0 THEN 0 ELSE 1 END', [now()])
            ->orderByRaw("CASE tasks.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")
            ->orderByRaw('CASE WHEN tasks.due_at BETWEEN ? AND ? THEN 0 ELSE 1 END', [now(), now()->addHours(24)])
            ->orderBy('tasks.created_at')
            ->limit($limit)
            ->get();
    }

    public function personalStatus(Task $task): string
    {
        if ($this->hasPersonalPendingSubmission($task)) {
            return 'awaiting_confirmation';
        }

        $assignment = $task->assignments->first();

        return $assignment instanceof TaskAssignment
            ? $assignment->status->value
            : ($task->status === TaskStatus::Pending ? TaskAssignmentStatus::Assigned->value : $task->status->value);
    }

    public function personalDueLabel(Task $task): string
    {
        if ($this->hasPersonalPendingSubmission($task)) {
            return 'Submitted for confirmation';
        }
        if ($task->due_at === null) {
            return 'No due date';
        }

        $seconds = now()->diffInSeconds($task->due_at, false);
        if ($seconds < 0) {
            $days = max(1, (int) ceil(abs($seconds) / 86400));

            return $days.' '.str('day')->plural($days).' overdue';
        }
        if (now()->isSameDay($task->due_at)) {
            return 'Due today';
        }
        if ($seconds < 86400) {
            $hours = max(1, (int) ceil($seconds / 3600));

            return $hours.' '.str('hour')->plural($hours).' left';
        }
        $days = max(1, (int) ceil($seconds / 86400));

        return $days.' '.str('day')->plural($days).' left';
    }

    public function forMode(User $user, string $mode): Builder
    {
        $query = $this->visible($user)->with(['assignments.employee:id,user_id,name,team_id', 'assignments.completionSubmissions', 'completionSubmissions', 'assignedEmployee:id,user_id,name,team_id', 'assignedTeam:id,name', 'createdBy:id,name', 'latestUpdate.actor:id,name']);

        return match ($mode) {
            'team' => $this->teamQuery($query, $user),
            'unassigned' => ($this->authorization->allows($user, TaskPermission::ManageAll) || $this->authorization->allows($user, TaskPermission::ViewTeam))
                ? $query->whereDoesntHave('assignments', fn (Builder $assignment) => $assignment->whereNotIn('status', ['removed', 'cancelled']))
                    ->whereNull('assigned_employee_id')->whereNull('assigned_team_id') : $query->whereRaw('1 = 0'),
            'all' => $this->authorization->allows($user, TaskPermission::ManageAll) ? $query : $query->whereRaw('1 = 0'),
            default => $query->where(function (Builder $mine) use ($user): void {
                $mine->whereHas('assignments', fn (Builder $assignment) => $assignment->where('employee_id', $user->employee?->id)->whereNotIn('status', ['cancelled', 'removed']))
                    ->orWhere(function (Builder $legacy) use ($user): void {
                        $legacy->whereDoesntHave('assignments')->where('assigned_employee_id', $user->employee?->id);
                    });
            }),
        };
    }

    /** @return array<string, int> */
    public function summary(Builder $query): array
    {
        $base = clone $query;
        $base->active();

        return [
            'pending' => (clone $base)->whereIn('status', [TaskStatus::Pending->value, TaskStatus::Assigned->value])->count(),
            'in_progress' => (clone $base)->where('status', TaskStatus::InProgress->value)->count(),
            'waiting' => (clone $base)->where('status', TaskStatus::Waiting->value)->count(),
            'awaiting_confirmation' => (clone $query)->whereHas('completionSubmissions', fn (Builder $submission) => $submission->where('status', 'pending'))->count(),
            'overdue' => (clone $base)->whereDoesntHave('completionSubmissions', fn (Builder $submission) => $submission->where('status', 'pending'))->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'due_soon' => (clone $base)->whereBetween('due_at', [now(), now()->addHours(24)])->count(),
            'completed' => (clone $query)->where('status', TaskStatus::Completed->value)->count(),
        ];
    }

    private function teamQuery(Builder $query, User $user): Builder
    {
        if (! $this->authorization->allows($user, TaskPermission::ViewTeam)) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->authorization->allows($user, TaskPermission::ManageAll)) {
            return $query->where(fn (Builder $q) => $q->whereHas('assignments')->orWhereNotNull('assigned_employee_id')->orWhereNotNull('assigned_team_id'));
        }
        if ($user->employee?->team_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user): void {
            $q->where('assigned_team_id', $user->employee->team_id)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('team_id_at_assignment', $user->employee->team_id)->whereNotIn('status', ['cancelled', 'removed']))
                ->orWhereHas('assignedEmployee', fn (Builder $e) => $e->where('team_id', $user->employee->team_id));
        });
    }

    /** @return array<int, string> */
    private function activeAssignmentStatuses(): array
    {
        return [
            TaskAssignmentStatus::Assigned->value,
            TaskAssignmentStatus::InProgress->value,
            TaskAssignmentStatus::Waiting->value,
        ];
    }

    private function constrainPersonalSubmissions(Builder|Relation $query, int $employeeId): Builder|Relation
    {
        return $query->where(function (Builder $personal) use ($employeeId): void {
            $personal->whereHas('assignment', fn (Builder $assignment) => $assignment->where('employee_id', $employeeId))
                ->orWhereNull('task_assignment_id');
        });
    }

    private function wherePersonalStatus(Builder $query, int $employeeId, TaskAssignmentStatus $status): void
    {
        $query->whereHas('assignments', fn (Builder $assignment) => $assignment
            ->where('employee_id', $employeeId)
            ->where('status', $status->value))
            ->orWhere(function (Builder $legacy) use ($employeeId, $status): void {
                $legacy->whereDoesntHave('assignments')
                    ->where('assigned_employee_id', $employeeId)
                    ->where('status', $status->value);
            });
    }

    private function hasPersonalPendingSubmission(Task $task): bool
    {
        return $task->completionSubmissions->contains(
            fn ($submission): bool => $submission->status === TaskCompletionSubmissionStatus::Pending,
        );
    }
}
