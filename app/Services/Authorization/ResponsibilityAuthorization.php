<?php

namespace App\Services\Authorization;

use App\Contracts\ResponsibilityPermissionResolver;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ResponsibilityAuthorization
{
    public function __construct(private readonly ResponsibilityPermissionResolver $resolver) {}

    public function allows(User $user, ResponsibilityPermission $permission, ?ResponsibilityAssignment $assignment = null): bool
    {
        if ($user->employee?->status !== true || $user->employee->user_id !== $user->id) {
            return false;
        }

        return $this->resolver->allows($user, $permission, $assignment);
    }

    public function canView(User $user, ResponsibilityAssignment $assignment): bool
    {
        if ($this->allows($user, ResponsibilityPermission::ViewAll, $assignment)) {
            return true;
        }

        $employee = $user->employee;

        if ($this->allows($user, ResponsibilityPermission::ViewOwn, $assignment)
            && $assignment->employee_id === $employee?->id) {
            return true;
        }

        return $this->allows($user, ResponsibilityPermission::ViewTeam, $assignment)
            && $employee?->team_id !== null
            && $assignment->employee()->where('team_id', $employee->team_id)->exists();
    }

    public function authorize(User $user, ResponsibilityPermission $permission, ?ResponsibilityAssignment $assignment = null): void
    {
        if (! $this->allows($user, $permission, $assignment)) {
            throw new AuthorizationException;
        }
    }
}
