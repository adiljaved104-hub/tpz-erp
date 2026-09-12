<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_mobile_dashboard(): void
    {
        $this->getJson('/api/mobile/v1/dashboard')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_authenticated_employee_can_view_mobile_dashboard(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile-dashboard@techpointzone.com',
        ]);

        Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => EmployeeRole::Owner,
        ]);

        $token = $user->createToken('Dashboard Test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/v1/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'period_label',
                    'cards',
                    'attention',
                    'responsibilities',
                    'role_label',
                ],
            ]);
    }
}
