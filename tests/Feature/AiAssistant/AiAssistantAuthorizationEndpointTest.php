<?php

namespace Tests\Feature\AiAssistant;

use App\Enums\EmployeeRole;
use App\Models\AiAssistantSetting;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantAuthorizationEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_render_erp_page_with_assistant_disabled(): void
    {
        config(['ai_assistant.enabled' => false]);
        $owner = $this->employeeUser(EmployeeRole::Owner);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('TPZ AI Assistant');
    }

    public function test_owner_can_access_assistant_endpoint_when_enabled(): void
    {
        $owner = $this->employeeUser(EmployeeRole::Owner);
        AiAssistantSetting::query()->create([
            'id' => 1,
            'enabled' => true,
            'provider' => 'mock',
            'model' => 'gpt-6-luna',
            'daily_budget_usd' => 2,
            'monthly_budget_usd' => 30,
        ]);

        $this->actingAs($owner)
            ->get('/admin/ai-assistant')
            ->assertOk()
            ->assertJsonPath('conversations', []);
    }

    public function test_unauthorized_employee_is_denied_from_assistant_endpoint(): void
    {
        $employee = $this->employeeUser(EmployeeRole::Staff);
        AiAssistantSetting::query()->create([
            'id' => 1,
            'enabled' => true,
            'provider' => 'mock',
            'model' => 'gpt-6-luna',
            'daily_budget_usd' => 2,
            'monthly_budget_usd' => 30,
        ]);

        $this->actingAs($employee)
            ->get('/admin/ai-assistant')
            ->assertForbidden();
    }

    private function employeeUser(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user, 'user')->role($role)->create();

        return $user;
    }
}
