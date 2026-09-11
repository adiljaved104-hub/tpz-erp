<?php

namespace Tests\Feature\Authentication;

use App\Actions\Employees\SetEmployeeStatus;
use App\Actions\Employees\UnlinkUserFromEmployee;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
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

    public function test_login_is_limited_per_normalized_account(): void
    {
        [$user] = $this->eligibleAccount();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/mobile/v1/auth/login', [
                'email' => mb_strtoupper($user->email),
                'password' => 'wrong-password',
                'device_name' => 'Owner iPhone',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Owner iPhone',
        ])->assertTooManyRequests();
    }

    public function test_shared_ip_does_not_apply_the_account_quota_collectively(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/mobile/v1/auth/login', [
                'email' => "unknown-{$attempt}@techpointzone.com",
                'password' => 'wrong-password',
                'device_name' => 'Warehouse phone',
            ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
        }
    }

    public function test_rejected_logins_create_sanitized_generic_audit_events(): void
    {
        [$wrongPasswordUser] = $this->eligibleAccount();
        [$inactiveUser, $inactiveEmployee] = $this->eligibleAccount();
        $inactiveEmployee->update(['status' => false]);
        [$twoFactorUser] = $this->eligibleAccount();
        $twoFactorUser->forceFill(['email_two_factor_enabled_at' => now()])->save();

        foreach ([
            [$wrongPasswordUser->email, 'wrong-password'],
            [$inactiveUser->email, 'password'],
            [$twoFactorUser->email, 'password'],
            ['unknown@techpointzone.com', 'password'],
        ] as [$email, $password]) {
            $this->postJson('/api/mobile/v1/auth/login', [
                'email' => $email,
                'password' => $password,
                'device_name' => 'Warehouse phone',
            ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
        }

        $failures = ActivityLog::query()->where('event', 'mobile_auth.login_failed')->get();
        $this->assertCount(4, $failures);
        foreach ($failures as $failure) {
            $this->assertNull($failure->actor_user_id);
            $this->assertNull($failure->subject_type);
            $this->assertNull($failure->subject_id);
            $this->assertNull($failure->properties);
        }
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

    public function test_deactivating_employee_revokes_all_device_tokens(): void
    {
        [$owner] = $this->ownerAccount();
        [$user, $employee] = $this->eligibleAccount();
        $user->createToken('Employee phone');
        $user->createToken('Employee tablet');

        app(SetEmployeeStatus::class)->handle($employee, false, $owner);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unlinking_employee_revokes_all_device_tokens(): void
    {
        [$owner] = $this->ownerAccount();
        [$user, $employee] = $this->eligibleAccount();
        $user->createToken('Employee phone');
        $user->createToken('Employee tablet');

        app(UnlinkUserFromEmployee::class)->handle($employee, $owner);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_request_time_ineligibility_revokes_all_device_tokens(): void
    {
        [$user, $employee] = $this->eligibleAccount();
        $presentedToken = $user->createToken('Employee phone')->plainTextToken;
        $user->createToken('Employee tablet');
        $employee->update(['status' => false]);

        $this->withToken($presentedToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertUnauthorized();

        $this->assertDatabaseCount('personal_access_tokens', 0);
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

    /** @return array{User, Employee} */
    private function ownerAccount(): array
    {
        [$user, $employee] = $this->eligibleAccount();
        $employee->update(['role' => EmployeeRole::Owner]);

        return [$user->refresh(), $employee->refresh()];
    }
}
