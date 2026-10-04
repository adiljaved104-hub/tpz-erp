<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [
        'inventory',
        'products',
        'orders',
        'modules',
        'manifest',
        'purchases',
        'stock-requests',
        'hr',
        'cases/claims',
        'cases/complaints',
        'internal-repairs',
        'responsibilities',
        'notifications',
        'returns',
        'warranty',
        'tasks',
    ];

    public function test_workspace_routes_require_authentication(): void
    {
        foreach ($this->paths as $path) {
            $this->getJson('/api/mobile/v1/workspace/'.$path)
                ->assertUnauthorized()
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }
    }

    public function test_mobile_api_guests_never_redirect_to_web_login(): void
    {
        $this->get('/api/mobile/v1/workspace/reports')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->get(
            '/api/mobile/v1/workspace/invoices/1/pdf',
            ['Accept' => 'application/pdf'],
        )
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
    public function test_owner_receives_server_driven_workspace_manifest(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile-manifest@techpointzone.com',
        ]);

        Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => EmployeeRole::Owner,
        ]);

        $token = $user->createToken('Manifest Test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/v1/workspace/manifest')
            ->assertOk()
            ->assertJsonPath('data.schema_version', 1)
            ->assertJsonPath('data.minimum_runtime_version', 1)
            ->assertJsonFragment([
                'key' => 'products',
                'renderer' => 'workspace',
                'api_path' => '/workspace/products',
                'record_module' => 'products',
            ])
            ->assertJsonFragment([
                'key' => 'chat',
                'renderer' => 'native',
                'api_path' => '/chat',
            ]);
    }

    public function test_owner_can_open_workspace_modules(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile-workspace@techpointzone.com',
        ]);

        Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => EmployeeRole::Owner,
        ]);

        $token = $user->createToken('Workspace Test')->plainTextToken;

        foreach ($this->paths as $path) {
            $this->withToken($token)
                ->getJson('/api/mobile/v1/workspace/'.$path)
                ->assertOk()
                ->assertJsonStructure(['data']);
        }
    }
}
