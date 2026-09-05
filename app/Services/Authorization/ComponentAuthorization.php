<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\ComponentPermission;
use App\Enums\EmployeeRole;
use App\Models\Component;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ComponentAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, ComponentPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => in_array($permission, [
                ComponentPermission::View,
                ComponentPermission::Create,
                ComponentPermission::Update,
                ComponentPermission::ChangeStatus,
            ], true),
            EmployeeRole::Manager => $permission === ComponentPermission::View,
            default => false,
        };
    }

    public function allows(User $user, ComponentPermission $permission, ?Component $component = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function authorize(User $user, ComponentPermission $permission, ?Component $component = null): void
    {
        throw_unless($this->allows($user, $permission, $component), AuthorizationException::class);
    }
}
