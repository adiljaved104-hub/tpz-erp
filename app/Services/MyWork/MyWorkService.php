<?php

namespace App\Services\MyWork;

use App\Enums\TaskPermission;
use App\Models\Task;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskQueryService;
use Illuminate\Database\Eloquent\Builder;

class MyWorkService
{
    public function __construct(private readonly TaskAuthorization $authorization, private readonly TaskQueryService $tasks) {}

    public function canAccess(User $user): bool
    {
        return $user->employee?->status === true && $this->authorization->allows($user, TaskPermission::View);
    }

    public function canViewTeam(User $user): bool
    {
        return $this->authorization->allows($user, TaskPermission::ViewTeam);
    }

    public function canViewUnassigned(User $user): bool
    {
        return $this->authorization->allows($user, TaskPermission::ManageAll) || ($this->canViewTeam($user) && $this->authorization->allows($user, TaskPermission::Assign));
    }

    public function query(User $user, string $mode = 'mine'): Builder
    {
        return $this->tasks->forMode($user, $this->normalizeMode($user, $mode))->active()->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('priority');
    }

    public function summary(User $user, string $mode = 'mine'): array
    {
        return $this->tasks->summary($this->tasks->forMode($user, $this->normalizeMode($user, $mode)));
    }

    public function nextAction(Task $task): string
    {
        if ($task->hasPendingCompletion()) {
            return 'Awaiting Confirmation';
        }

        $assignment = $task->assignments->firstWhere('employee_id', auth()->user()?->employee?->id);

        return match ($assignment?->status?->value ?? $task->status->value) {
            'assigned', 'pending' => 'Start Task',
            'waiting' => 'Resume Task',
            default => 'Open Task',
        };
    }

    private function normalizeMode(User $user, string $mode): string
    {
        return match ($mode) {
            'team' => $this->canViewTeam($user) ? 'team' : 'mine', 'unassigned' => $this->canViewUnassigned($user) ? 'unassigned' : 'mine', default => 'mine'
        };
    }
}
