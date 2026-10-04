<?php

namespace Tests\Feature\Authentication;

use App\Actions\Employees\SetEmployeeStatus;
use App\Actions\Employees\UnlinkUserFromEmployee;
use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeeRole;
use App\Models\ActivityLog;
use App\Models\AuthenticationOtpChallenge;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use App\Services\AuthenticationOtpService;
use App\Services\Security\LoginAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
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
            'device_name' => 'Staff iPhone',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type'])
            ->assertJsonPath('token_type', 'Bearer');
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Staff iPhone',
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

        $this->assertDatabaseCount('authentication_otp_challenges', 0);
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

    public function test_privileged_mobile_login_requires_mfa_and_does_not_issue_a_token(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();

        $response = $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertAccepted()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonPath('resend_after', AuthenticationOtpService::RESEND_COOLDOWN_SECONDS)
            ->assertJsonStructure(['challenge_id', 'message', 'expires_in', 'resend_after'])
            ->assertJsonMissing(['token', 'token_type']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('authentication_otp_challenges', [
            'id' => $response->json('challenge_id'),
            'user_id' => $user->id,
            'purpose' => AuthenticationOtpPurpose::TwoFactor->value,
        ]);
        Notification::assertSentTo($user, AuthenticationOtpNotification::class);
    }

    public function test_valid_mobile_mfa_code_after_valid_password_issues_the_normal_token_response(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);
        $code = $this->sentOtp($user)->code;

        $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk()
            ->assertJsonStructure(['token', 'token_type'])
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Owner iPhone',
        ]);
        $this->assertNotNull(AuthenticationOtpChallenge::query()->findOrFail($challengeId)->consumed_at);
    }

    public function test_invalid_mobile_mfa_code_is_rejected_without_a_token(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);

        $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'code' => '111111',
        ])->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid or expired verification code.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(1, AuthenticationOtpChallenge::query()->findOrFail($challengeId)->attempts);
    }

    public function test_expired_mobile_mfa_code_is_rejected(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);
        $code = $this->sentOtp($user)->code;
        AuthenticationOtpChallenge::query()->findOrFail($challengeId)
            ->forceFill(['expires_at' => now()->subSecond()])
            ->save();

        $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_consumed_mobile_mfa_challenge_cannot_be_reused(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);
        $code = $this->sentOtp($user)->code;
        $payload = ['challenge_id' => $challengeId, 'code' => $code];

        $this->postJson('/api/mobile/v1/auth/mfa/verify', $payload)->assertOk();
        $this->postJson('/api/mobile/v1/auth/mfa/verify', $payload)
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid or expired verification code.']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_active_password_lockout_cannot_be_bypassed_by_mobile_mfa_endpoint(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);
        $code = $this->sentOtp($user)->code;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            app(LoginAttemptService::class)->recordFailure($user->email, '127.0.0.1', $user, 'mobile');
        }

        $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too many sign-in attempts. Try again later.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(AuthenticationOtpChallenge::query()->findOrFail($challengeId)->consumed_at);
        app(LoginAttemptService::class)->clear($user->email, '127.0.0.1');
    }

    public function test_password_rotation_requirement_takes_precedence_over_privileged_mobile_mfa(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $user->forceFill(['password_changed_at' => now()->subDays(366)])->save();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertStatus(428)
            ->assertJsonPath('code', 'password_rotation_required')
            ->assertJsonMissing(['mfa_required', 'challenge_id', 'token']);

        $this->assertDatabaseCount('authentication_otp_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_mobile_mfa_challenge_cannot_be_used_without_password_verified_context(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challenge = app(AuthenticationOtpService::class)->issue(
            $user,
            AuthenticationOtpPurpose::TwoFactor,
            '127.0.0.1',
        );
        $code = $this->sentOtp($user)->code;

        $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challenge->id,
            'code' => $code,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($challenge->refresh()->consumed_at);
    }

    public function test_mobile_mfa_resend_cooldown_is_preserved_by_repeated_password_login(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $this->beginMfa($user);

        $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertTooManyRequests()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonStructure(['message', 'retry_after'])
            ->assertJsonMissing(['challenge_id', 'token']);

        $this->assertDatabaseCount('authentication_otp_challenges', 1);
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

        for ($attempt = 0; $attempt < 9; $attempt++) {
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
        ])->assertUnauthorized();

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
        foreach ([
            [$wrongPasswordUser->email, 'wrong-password'],
            [$inactiveUser->email, 'password'],
            ['unknown@techpointzone.com', 'password'],
        ] as [$email, $password]) {
            $this->postJson('/api/mobile/v1/auth/login', [
                'email' => $email,
                'password' => $password,
                'device_name' => 'Warehouse phone',
            ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials.']);
        }

        $failures = ActivityLog::query()->where('event', 'mobile_auth.login_failed')->get();
        $this->assertCount(3, $failures);
        foreach ($failures as $failure) {
            $this->assertNull($failure->actor_user_id);
            $this->assertNull($failure->subject_type);
            $this->assertNull($failure->subject_id);
            $this->assertNull($failure->properties);
        }
    }

    public function test_mobile_mfa_audit_metadata_contains_no_password_code_challenge_or_access_token(): void
    {
        Notification::fake();
        [$user] = $this->ownerAccount();
        $challengeId = $this->beginMfa($user);
        $code = $this->sentOtp($user)->code;

        $response = $this->postJson('/api/mobile/v1/auth/mfa/verify', [
            'challenge_id' => $challengeId,
            'code' => $code,
        ])->assertOk();

        $auditPayload = ActivityLog::query()
            ->whereIn('event', ['mobile_auth.mfa_challenge_issued', 'mobile_auth.login_succeeded'])
            ->get()
            ->toJson();

        $this->assertStringNotContainsString('password', mb_strtolower($auditPayload));
        $this->assertStringNotContainsString($challengeId, $auditPayload);
        $this->assertStringNotContainsString($code, $auditPayload);
        $this->assertStringNotContainsString((string) $response->json('token'), $auditPayload);
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

    private function beginMfa(User $user): string
    {
        return (string) $this->postJson('/api/mobile/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Owner iPhone',
        ])->assertAccepted()->json('challenge_id');
    }

    private function sentOtp(User $user): AuthenticationOtpNotification
    {
        $notification = null;
        Notification::assertSentTo(
            $user,
            AuthenticationOtpNotification::class,
            function (AuthenticationOtpNotification $sent) use (&$notification): bool {
                if ($sent->purpose !== AuthenticationOtpPurpose::TwoFactor) {
                    return false;
                }

                $notification = $sent;

                return true;
            },
        );

        return $notification;
    }
}
