<?php

namespace Tests\Feature\Navigation;

use App\Enums\EmployeeRole;
use App\Filament\Pages\CustomizeNavigation;
use App\Models\Employee;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceNavigationOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_opt_into_simplified_workspace_navigation_without_changing_operational_permissions(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;

        $this->actingAs($owner)->get(CustomizeNavigation::getUrl())->assertOk()->assertSee('Simplified workspace navigation');
        $this->actingAs($owner);
        Livewire::test(CustomizeNavigation::class)->call('toggleWorkspaceMode');

        $this->assertTrue(app(NavigationPreferenceService::class)->workspaceModeEnabled($owner));
        $this->assertSame([], app(UserUiPreferenceService::class)->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
    }

    public function test_workspace_mode_preference_is_not_a_new_access_control_permission(): void
    {
        $this->assertSame('navigation.workspace_mode', UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE);
    }
}
