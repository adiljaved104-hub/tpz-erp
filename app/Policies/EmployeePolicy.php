<?php

namespace App\Policies;

use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\PeopleAuthorization;

class EmployeePolicy
{
    public function __construct(private readonly PeopleAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PeoplePermission::EmployeeView);
    }

    public function view(User $user, Employee $employee): bool
    {
        if ($user->employee?->is($employee)) {
            return true;
        }

        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->employee?->role === EmployeeRole::Manager) {
            return $user->employee->team_id !== null
                && $user->employee->team_id === $employee->team_id;
        }

        return true;
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, PeoplePermission::EmployeeCreate);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->canAffect($user, $employee, PeoplePermission::EmployeeUpdate);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }

    public function restore(User $user, Employee $employee): bool
    {
        return false;
    }

    public function forceDelete(User $user, Employee $employee): bool
    {
        return false;
    }

    public function linkUser(User $user, Employee $employee): bool
    {
        return $this->canAffect($user, $employee, PeoplePermission::EmployeeLinkUser);
    }

    public function unlinkUser(User $user, Employee $employee): bool
    {
        return $this->canAffect($user, $employee, PeoplePermission::EmployeeUnlinkUser);
    }

    public function changeRole(User $user, Employee $employee): bool
    {
        return $this->canAffect($user, $employee, PeoplePermission::EmployeeChangeRole);
    }

    public function changeStatus(User $user, Employee $employee): bool
    {
        return $this->canAffect($user, $employee, PeoplePermission::EmployeeChangeStatus);
    }

    public function managePermissions(User $user, Employee $employee): bool
    {
        if (! $this->isOwnerOrAdmin($user) || $employee->role === EmployeeRole::Owner) {
            return false;
        }

        return $this->isOwner($user) || ! $user->employee?->is($employee);
    }

    private function isActive(User $user): bool
    {
        return $user->employee?->status === true;
    }

    private function canAffect(User $user, Employee $employee, PeoplePermission $permission): bool
    {
        if (! $this->authorization->allows($user, $permission)) {
            return false;
        }

        return $employee->role !== EmployeeRole::Owner || $this->isOwner($user);
    }

    private function isOwnerOrAdmin(User $user): bool
    {
        return $this->isOwner($user) || $this->isAdmin($user);
    }

    private function isOwner(User $user): bool
    {
        return $this->isActive($user) && $user->employee->role === EmployeeRole::Owner;
    }

    private function isAdmin(User $user): bool
    {
        return $this->isActive($user) && $user->employee->role === EmployeeRole::Admin;
    }
}
