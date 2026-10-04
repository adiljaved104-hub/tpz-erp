<?php

namespace App\Services\Authorization;

use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class EmployeeDirectoryScopeService
{
    // Visibility restriction only: callers retain their existing permission checks.
    public function apply(Builder|QueryBuilder $query, ?User $user): void
    {
        $employee = $user?->employee;

        if ($employee?->role !== EmployeeRole::Manager) {
            return;
        }

        if ($employee->team_id === null) {
            // A missing team is not a shared team. Preserve access to the Manager's own record.
            $query->where('employees.id', $employee->getKey());

            return;
        }

        $query->where('employees.team_id', $employee->team_id);
    }
}
