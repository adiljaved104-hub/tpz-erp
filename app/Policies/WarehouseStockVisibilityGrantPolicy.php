<?php

namespace App\Policies;

use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Models\WarehouseStockVisibilityGrant;
use App\Services\Authorization\ResponsibilityAuthorization;

class WarehouseStockVisibilityGrantPolicy
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::ViewAll)
            || $this->authorization->allows($user, ResponsibilityPermission::ViewTeam)
            || $this->authorization->allows($user, ResponsibilityPermission::ViewOwn);
    }

    public function view(User $user, WarehouseStockVisibilityGrant $grant): bool
    {
        if ($this->authorization->allows($user, ResponsibilityPermission::ViewAll)) {
            return true;
        }

        $employee = $user->employee;

        return ($this->authorization->allows($user, ResponsibilityPermission::ViewOwn)
                && $grant->employee_id === $employee?->id)
            || ($employee?->team_id !== null
                && $this->authorization->allows($user, ResponsibilityPermission::ViewTeam)
                && $grant->employee()->where('team_id', $employee->team_id)->exists());
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::Assign);
    }

    public function deactivate(User $user, WarehouseStockVisibilityGrant $grant): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::Deactivate)
            && $this->view($user, $grant);
    }

    public function update(User $user, WarehouseStockVisibilityGrant $grant): bool
    {
        return false;
    }

    public function delete(User $user, WarehouseStockVisibilityGrant $grant): bool
    {
        return false;
    }
}
