<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AiAssistantPermission;
use App\Enums\EmployeeRole;
use App\Models\AiAssistantConversation;
use App\Models\User;

class AiAssistantAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function allows(User $user, AiAssistantPermission $permission): bool
    {
        $employee = $user->employee;
        if ($employee?->status !== true) {
            return false;
        }
        if ($employee->role === EmployeeRole::Owner) {
            return true;
        }
        if ($employee->role !== EmployeeRole::Admin) {
            return false;
        }

        return $this->overrides->decision($user, $permission->value) === true;
    }

    public function owns(User $user, AiAssistantConversation $conversation): bool
    {
        return $conversation->user_id === $user->id && $this->allows($user, AiAssistantPermission::Use);
    }
}
