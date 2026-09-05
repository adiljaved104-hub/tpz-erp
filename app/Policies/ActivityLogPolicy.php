<?php

namespace App\Policies;

use App\Enums\PeoplePermission;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Authorization\PeopleAuthorization;

class ActivityLogPolicy
{
    public function __construct(private readonly PeopleAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PeoplePermission::ActivityLogView);
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function delete(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function restore(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }

    public function forceDelete(User $user, ActivityLog $activityLog): bool
    {
        return false;
    }
}
