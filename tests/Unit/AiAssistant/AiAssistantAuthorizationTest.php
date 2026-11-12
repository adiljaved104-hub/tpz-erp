<?php

namespace Tests\Unit\AiAssistant;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AiAssistantPermission;
use App\Enums\EmployeeRole;
use App\Models\AiAssistantConversation;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\AiAssistantAuthorization;
use PHPUnit\Framework\TestCase;

class AiAssistantAuthorizationTest extends TestCase
{
    public function test_owner_can_use_assistant_without_override(): void
    {
        $auth = new AiAssistantAuthorization($this->overrides(null));
        self::assertTrue($auth->allows($this->user(EmployeeRole::Owner), AiAssistantPermission::Use));
    }

    public function test_admin_requires_an_explicit_allow_override(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        self::assertFalse((new AiAssistantAuthorization($this->overrides(null)))->allows($admin, AiAssistantPermission::Use));
        self::assertTrue((new AiAssistantAuthorization($this->overrides(true)))->allows($admin, AiAssistantPermission::Use));
    }

    public function test_other_and_inactive_employees_are_denied(): void
    {
        self::assertFalse((new AiAssistantAuthorization($this->overrides(true)))->allows($this->user(EmployeeRole::Manager), AiAssistantPermission::Use));
        self::assertFalse((new AiAssistantAuthorization($this->overrides(true)))->allows($this->user(EmployeeRole::Admin, false), AiAssistantPermission::Use));
    }

    public function test_conversation_ownership_is_bound_to_the_authenticated_user(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $conversation = new AiAssistantConversation(['user_id' => $owner->id]);
        $authorization = new AiAssistantAuthorization($this->overrides(null));
        self::assertTrue($authorization->owns($owner, $conversation));
        $other = $this->user(EmployeeRole::Owner);
        $other->forceFill(['id' => 5]);
        self::assertFalse($authorization->owns($other, $conversation));
    }

    private function overrides(?bool $decision): EmployeePermissionOverrideResolver
    {
        return new class($decision) implements EmployeePermissionOverrideResolver
        {
            public function __construct(private readonly ?bool $decision) {}

            public function decision(User $user, string $permissionKey): ?bool
            {
                return $permissionKey === AiAssistantPermission::Use->value ? $this->decision : null;
            }

            public function forgetEmployee(int $employeeId): void {}
        };
    }

    private function user(EmployeeRole $role, bool $active = true): User
    {
        $user = new User;
        $user->forceFill(['id' => 4]);
        $employee = new Employee(['status' => $active, 'role' => $role]);
        $employee->user_id = $user->id;
        $user->setRelation('employee', $employee);

        return $user;
    }
}
