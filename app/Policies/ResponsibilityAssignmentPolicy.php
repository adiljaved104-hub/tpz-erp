<?php

namespace App\Policies;

use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;

class ResponsibilityAssignmentPolicy
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::ViewAll)
            || $this->authorization->allows($user, ResponsibilityPermission::ViewTeam)
            || $this->authorization->allows($user, ResponsibilityPermission::ViewOwn);
    }

    public function view(User $user, ResponsibilityAssignment $assignment): bool
    {
        return $this->authorization->canView($user, $assignment);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::Assign);
    }

    public function transfer(User $user, ResponsibilityAssignment $assignment): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::Reassign, $assignment);
    }

    public function changeQuantity(User $user, ResponsibilityAssignment $assignment): bool
    {
        return $this->transfer($user, $assignment);
    }

    public function deactivate(User $user, ResponsibilityAssignment $assignment): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::Deactivate, $assignment);
    }

    public function update(User $user, ResponsibilityAssignment $assignment): bool
    {
        return false;
    }

    public function delete(User $user, ResponsibilityAssignment $assignment): bool
    {
        return false;
    }

    public function restore(User $user, ResponsibilityAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, ResponsibilityAssignment $assignment): bool
    {
        return false;
    }
}
