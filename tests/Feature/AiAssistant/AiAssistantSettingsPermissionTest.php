<?php

namespace Tests\Feature\AiAssistant;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\AiAssistantPermission;
use App\Enums\EmployeeRole;
use App\Filament\Pages\AiAssistantSettings;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiAssistantSettingsPermissionTest extends TestCase
{
    use RefreshDatabase;

    public static function accessCases(): array
    {
        return [
            'Owner without override' => [EmployeeRole::Owner, true, null, true],
            'Owner protection with deny' => [EmployeeRole::Owner, true, 'deny', true],
            'Admin explicitly allowed' => [EmployeeRole::Admin, true, 'allow', true],
            'Admin without grant' => [EmployeeRole::Admin, true, null, false],
            'Admin explicitly denied' => [EmployeeRole::Admin, true, 'deny', false],
            'Staff with grant' => [EmployeeRole::Staff, true, 'allow', false],
            'Inactive Admin with grant' => [EmployeeRole::Admin, false, 'allow', false],
            'Inactive Owner' => [EmployeeRole::Owner, false, null, false],
        ];
    }

    #[DataProvider('accessCases')]
    public function test_page_and_save_enforce_the_same_settings_permission(EmployeeRole $role, bool $active, ?string $effect, bool $allowed): void
    {
        $user = $this->employeeUser($role, $active);
        if ($effect !== null) {
            $this->override($user, AiAssistantPermission::ManageSettings, $effect);
        }
        $this->actingAs($user);
        $this->assertSame($allowed, AiAssistantSettings::canAccess());

        if ($allowed) {
            $this->get(AiAssistantSettings::getUrl())->assertOk();
            Livewire::test(AiAssistantSettings::class)
                ->set('provider', 'mock')
                ->set('dailyBudget', 7)
                ->call('save')
                ->assertHasNoErrors();
            $this->assertDatabaseHas('ai_assistant_settings', ['id' => 1, 'daily_budget_usd' => '7.00']);
        } else {
            $this->get(AiAssistantSettings::getUrl())->assertForbidden();
            Livewire::test(AiAssistantSettings::class)->assertForbidden();
            // A forged save must fail even without passing through mount().
            try {
                (new AiAssistantSettings)->save();
                $this->fail('Unauthorized settings save was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertDatabaseCount('ai_assistant_settings', 0);
        }
    }

    public function test_revoked_admin_grant_blocks_an_already_open_livewire_save(): void
    {
        $user = $this->employeeUser(EmployeeRole::Admin);
        $override = $this->override($user, AiAssistantPermission::ManageSettings, 'allow');
        $this->actingAs($user);
        $page = Livewire::test(AiAssistantSettings::class)->set('dailyBudget', 8);
        $override->update(['effect' => 'deny']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);

        $page->call('save')->assertForbidden();
        $this->assertFalse(AiAssistantSettings::canAccess());
        $this->assertDatabaseCount('ai_assistant_settings', 0);
    }

    public function test_assistant_use_permission_does_not_grant_settings_access(): void
    {
        $user = $this->employeeUser(EmployeeRole::Admin);
        $this->override($user, AiAssistantPermission::Use, 'allow');
        $this->actingAs($user);

        $this->assertFalse(AiAssistantSettings::canAccess());
        Livewire::test(AiAssistantSettings::class)->assertForbidden();
    }

    public function test_authorized_admin_still_receives_existing_form_validation(): void
    {
        $user = $this->employeeUser(EmployeeRole::Admin);
        $this->override($user, AiAssistantPermission::ManageSettings, 'allow');
        $this->actingAs($user);

        Livewire::test(AiAssistantSettings::class)->set('dailyBudget', -1)
            ->call('save')->assertHasErrors(['dailyBudget']);
        $this->assertDatabaseCount('ai_assistant_settings', 0);
    }

    private function employeeUser(EmployeeRole $role, bool $active = true): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['status' => $active, 'email' => $user->email]);

        return $user;
    }

    private function override(User $user, AiAssistantPermission $permission, string $effect): EmployeePermissionOverride
    {
        return EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id, 'permission_key' => $permission->value,
            'effect' => $effect, 'granted_by_user_id' => $user->id, 'reason' => 'Isolated permission regression fixture',
        ]);
    }
}
