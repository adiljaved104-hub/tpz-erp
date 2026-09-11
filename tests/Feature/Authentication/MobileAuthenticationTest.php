<?php

namespace Tests\Feature\Authentication;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_linked_employee_can_log_in(): void
    {
        [$user] = $this->eligibleAccount();

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type'])
            ->assertJsonPath('token_type', 'Bearer');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Owner iPhone',
        ]);
    }

    public function test_wrong_password_returns_generic_invalid_credentials_response(): void
    {
        [$user] = $this->eligibleAccount();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Owner iPhone',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_inactive_employee_cannot_log_in(): void
    {
        [$user, $employee] = $this->eligibleAccount();
        $employee->update(['status' => false]);

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_two_factor_enabled_user_cannot_log_in_with_password_alone(): void
    {
        [$user] = $this->eligibleAccount();
        $user->forceFill(['email_two_factor_enabled_at' => now()])->save();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unlinked_user_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'unlinked@techpointzone.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
    }

    public function test_authenticated_user_can_view_minimal_profile(): void
    {
        [$user, $employee] = $this->eligibleAccount();
        $token = $user->createToken('Owner iPhone')->plainTextToken;

        $this->withToken($token)->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ],
                    'employee' => [
                        'id' => $employee->id,
                        'employee_id' => $employee->employee_id,
                        'name' => $employee->name,
                        'designation' => $employee->designation,
                        'role' => $employee->role->value,
                    ],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_view_profile(): void
    {
        $this->getJson('/api/mobile/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_logout_revokes_only_current_token(): void
    {
        [$user] = $this->eligibleAccount();
        $currentToken = $user->createToken('Owner iPhone')->plainTextToken;
        $otherToken = $user->createToken('Owner iPad');

        $this->withToken($currentToken)->postJson('/api/mobile/v1/auth/logout')
            ->assertOk()
            ->assertExactJson(['message' => 'Logged out.']);

        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        Auth::forgetGuards();

        $this->withToken($currentToken)->getJson('/api/mobile/v1/auth/me')->assertUnauthorized();
    }

    /** @return array{User, Employee} */
    private function eligibleAccount(): array
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
            'password' => 'password',
        ]);
        $employee = Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return [$user, $employee];
    }
}
