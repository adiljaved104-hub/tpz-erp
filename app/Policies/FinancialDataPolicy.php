<?php

namespace App\Policies;

use App\Enums\EmployeeRole;
use App\Models\User;

class FinancialDataPolicy
{
    public function view(User $user): bool
    {
        return $user->employee?->status === true && $user->employee->role === EmployeeRole::Owner;
    }
}
