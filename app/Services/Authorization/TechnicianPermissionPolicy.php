<?php

namespace App\Services\Authorization;

use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Models\Employee;

/** Central hard allowlist for the isolated Technician role. */
final class TechnicianPermissionPolicy
{
    /** @return array<int, string> */
    public function allowedPermissionKeys(): array
    {
        return [
            QcPermission::View->value,
            QcPermission::Update->value,
            QcPermission::Complete->value,
            QcPermission::ViewCustomerEvidence->value,
        ];
    }

    public function allowsPermissionKey(string $permissionKey): bool
    {
        return in_array($permissionKey, $this->allowedPermissionKeys(), true);
    }

    public function restricts(Employee $employee, string $permissionKey): bool
    {
        return $employee->role === EmployeeRole::Technician && ! $this->allowsPermissionKey($permissionKey);
    }
}
