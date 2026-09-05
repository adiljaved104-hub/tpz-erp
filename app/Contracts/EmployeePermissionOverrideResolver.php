<?php

namespace App\Contracts;

use App\Models\User;

interface EmployeePermissionOverrideResolver
{
    public function decision(User $user, string $permissionKey): ?bool;

    public function forgetEmployee(int $employeeId): void;
}
