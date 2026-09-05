<?php

namespace App\Policies;

use App\Enums\PeoplePermission;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\PeopleAuthorization;

class TeamPolicy
{
    public function __construct(private readonly PeopleAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PeoplePermission::TeamView);
    }

    public function view(User $user, Team $team): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, PeoplePermission::TeamManage);
    }

    public function update(User $user, Team $team): bool
    {
        return $this->authorization->allows($user, PeoplePermission::TeamManage);
    }

    public function delete(User $user, Team $team): bool
    {
        return false;
    }

    public function restore(User $user, Team $team): bool
    {
        return false;
    }

    public function forceDelete(User $user, Team $team): bool
    {
        return false;
    }
}
