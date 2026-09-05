<?php

namespace App\Services\Tasks;

use App\Enums\TaskPermission;
use App\Models\Employee;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;

class TaskAssigneeService
{
    public function __construct(private readonly TaskAuthorization $authorization) {}

    /** @return array<int, string> */
    public function options(?Task $task = null, ?User $actor = null): array
    {
        $actor ??= auth()->user();

        return Employee::query()->where('status', true)->whereNotNull('user_id')->with('user:id,name')->orderBy('name')->get()
            ->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->allows($employee->user, TaskPermission::View)
                && $actor instanceof User
                && $this->authorization->canAssignEmployee($actor, $employee))
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => $employee->name.' — '.$employee->employee_id])->all();
    }

    /** @return array<int, string> */
    public function teamOptions(?User $actor = null): array
    {
        $actor ??= auth()->user();

        return Team::query()->where('status', true)->orderBy('name')->get()
            ->filter(fn (Team $team): bool => $actor instanceof User && $this->authorization->canAssignTeam($actor, $team))
            ->pluck('name', 'id')->all();
    }

    /** @return array<int, int> */
    public function eligibleTeamMemberIds(int $teamId, User $actor): array
    {
        return Employee::query()->where('team_id', $teamId)->where('status', true)->whereNotNull('user_id')->with('user')->orderBy('id')->get()
            ->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->allows($employee->user, TaskPermission::View)
                && $this->authorization->canAssignEmployee($actor, $employee))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function eligibleTeamMemberCount(?int $teamId, ?User $actor = null): int
    {
        if ($teamId === null || ! $actor instanceof User) {
            return 0;
        }

        return count($this->eligibleTeamMemberIds($teamId, $actor));
    }
}
