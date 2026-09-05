<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Models\EmployeePermissionOverride;
use App\Models\User;

class DatabaseEmployeePermissionOverrideResolver implements EmployeePermissionOverrideResolver
{
    /** @var array<int, array<string, bool>> */
    private array $memoized = [];

    public function decision(User $user, string $permissionKey): ?bool
    {
        $employeeId = $user->employee?->getKey();

        if ($employeeId === null) {
            return null;
        }

        if (! array_key_exists($employeeId, $this->memoized)) {
            $this->memoized[$employeeId] = EmployeePermissionOverride::query()
                ->where('employee_id', $employeeId)
                ->get(['permission_key', 'effect'])
                ->mapWithKeys(fn (EmployeePermissionOverride $override): array => [
                    $override->permission_key => $override->effect === EmployeePermissionEffect::Allow,
                ])
                ->all();
        }

        return $this->memoized[$employeeId][$permissionKey] ?? null;
    }

    public function forgetEmployee(int $employeeId): void
    {
        unset($this->memoized[$employeeId]);
    }
}
