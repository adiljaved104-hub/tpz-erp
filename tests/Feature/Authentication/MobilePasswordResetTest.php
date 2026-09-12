<?php

namespace Tests\Feature\Authentication;

use App\Enums\AuthenticationOtpPurpose;
use App\Models\AuthenticationOtpChallenge;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobilePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_employee_can_request_password_reset_code(): void
    {
        Notification::fake();
        $user = $this->eligibleAccount();

        $response = $this->postJson('/api/mobile/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'challenge_id'])
            ->assertJsonPath(
                'message',
                'If the email is eligible, a verification code has been sent.',
            );

        $this->assertDatabaseCount('authentication_otp_challenges', 1);

        $this->sentOtp($user);
    }

    public function test_unknown_email_receives_same_generic_response(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/mobile/v1/auth/password/forgot', [
            'email' => 'unknown@techpointzone.com',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'challenge_id'])
            ->assertJsonPath(
                'message',
                'If the email is eligible, a verification code has been sent.',
            );

        $this->assertDatabaseCount('authentication_otp_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_valid_code_returns_short_lived_reset_token(): void
    {
        Notification::fake();
        $user = $this->eligibleAccount();

        $forgot = $this->postJson('/api/mobile/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $code = $this->sentOtp($user)->code;

        $this->postJson('/api/mobile/v1/auth/password/verify', [
            'challenge_id' => $forgot->json('challenge_id'),
            'code' => $code,
        ])->assertOk()
            ->assertJsonStructure(['reset_token', 'expires_in'])
            ->assertJsonPath('expires_in', 600);

        $this->assertNotNull(
            AuthenticationOtpChallenge::query()->sole()->consumed_at,
        );
    }

    public function test_invalid_code_is_rejected(): void
    {
        Notification::fake();
        $user = $this->eligibleAccount();

        $forgot = $this->postJson('/api/mobile/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $this->postJson('/api/mobile/v1/auth/password/verify', [
            'challenge_id' => $forgot->json('challenge_id'),
            'code' => '111111',
        ])->assertStatus(422)
            ->assertExactJson([
                'message' => 'The verification code is invalid or has expired.',
            ]);
    }

    public function test_verified_employee_can_reset_password_and_all_tokens_are_revoked(): void
    {
        Notification::fake();
        $user = $this->eligibleAccount();

        $phoneToken = $user->createToken('Employee phone');
        $tabletToken = $user->createToken('Employee tablet');

        $forgot = $this->postJson('/api/mobile/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $code = $this->sentOtp($user)->code;

        $verify = $this->postJson('/api/mobile/v1/auth/password/verify', [
            'challenge_id' => $forgot->json('challenge_id'),
            'code' => $code,
        ])->assertOk();

        $this->postJson('/api/mobile/v1/auth/password/reset', [
            'reset_token' => $verify->json('reset_token'),
            'password' => 'New-Secure-Password-2026!',
            'password_confirmation' => 'New-Secure-Password-2026!',
        ])->assertOk()
            ->assertExactJson([
                'message' => 'Password updated. You can now sign in.',
            ]);

        $this->assertTrue(
            Hash::check(
                'New-Secure-Password-2026!',
                $user->refresh()->password,
            ),
        );

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $phoneToken->accessToken->id,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tabletToken->accessToken->id,
        ]);

        $this->assertSame(
            0,
            PersonalAccessToken::query()
                ->where('tokenable_id', $user->id)
                ->count(),
        );

        Auth::forgetGuards();

        $this->withToken($phoneToken->plainTextToken)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertUnauthorized();
    }

    private function eligibleAccount(): User
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
            'password' => 'password',
        ]);

        Employee::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh()->load('employee');
    }

    private function sentOtp(User $user): AuthenticationOtpNotification
    {
        $notification = null;

        Notification::assertSentTo(
            $user,
            AuthenticationOtpNotification::class,
            function (AuthenticationOtpNotification $sent) use (&$notification): bool {
                if ($sent->purpose !== AuthenticationOtpPurpose::PasswordReset) {
                    return false;
                }

                $notification = $sent;

                return true;
            },
        );

        return $notification;
    }
}
