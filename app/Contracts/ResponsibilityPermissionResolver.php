<?php

namespace App\Contracts;

use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;

interface ResponsibilityPermissionResolver
{
    public function allows(User $user, ResponsibilityPermission $permission, ?ResponsibilityAssignment $assignment = null): bool;
}
