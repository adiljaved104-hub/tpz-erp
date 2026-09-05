<?php

namespace App\Contracts;

use App\Enums\PeoplePermission;
use App\Models\User;

interface PeoplePermissionResolver
{
    public function allows(User $user, PeoplePermission $permission): bool;
}
