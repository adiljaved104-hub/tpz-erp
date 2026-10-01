<?php

namespace Tests\Feature\Authentication;

use App\Auth\EmailOtpAuthenticationProvider;
use App\Enums\EmployeeRole;
use App\Filament\Auth\Login;
use App\Http\Middleware\EnforceWebSecurityPolicy;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\ApplicationSecurityPolicy;
use App\Services\Security\LoginAttemptService;
use App\Services\Security\MfaPolicy;
use App\Services\Security\PasswordAgeService;
use App\Services\TwoFactorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationSecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_password_policy_rejects_weak_passwords_and_accepts_a_compliant_password(): void
    {
        $this->assertTrue(Validator::make(['password' => 'Short1!'], ['password' => ['required', Password::defaults()]])->fails());
        $this->assertTrue(Validator::make(['password' => 'LongPassword123'], ['password' => ['required', Password::defaults()]])->fails());
        $this->assertFalse(Validator::make(['password' => 'LongPassword1!'], ['password' => ['required', Password::defaults()]])->fails());
        $this->assertSame(12, ApplicationSecurityPolicy::PASSWORD_MIN_LENGTH);
    }

    public function test_password_age_uses_safe_existing_account_baseline_and_detects_expiry(): void
    {
        $current = User::factory()->create(['password_changed_at' => null]);
        $expired = User::factory()->create(['password_changed_at' => now()->subDays(366)]);

        $this->assertFalse(app(PasswordAgeService::class)->isExpired($current));
        $this->assertTrue(app(PasswordAgeService::class)->isExpired($expired));
    }

    public function test_web_and_mobile_password_reset_paths_reject_the_same_weak_password(): void
    {
        $user = $this->user(EmployeeRole::Staff);

        $this->withSession(['auth_otp.password_reset_user' => $user->id])
            ->post(route('auth.password.update'), [
                'password' => 'MissingSymbol123',
                'password_confirmation' => 'MissingSymbol123',
            ])
            ->assertSessionHasErrors('password');

        $this->postJson('/api/mobile/v1/auth/password/reset', [
            'reset_token' => 'disposable-test-token',
            'password' => 'MissingSymbol123',
            'password_confirmation' => 'MissingSymbol123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_mfa_is_mandatory_for_privileged_roles_but_not_ordinary_staff(): void
    {
        $policy = app(MfaPolicy::class);

        foreach ([EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager] as $role) {
            $user = $this->user($role);
            $this->assertTrue($policy->requires($user));
            $this->assertTrue(app(EmailOtpAuthenticationProvider::class)->isEnabled($user));
        }

        $staff = $this->user(EmployeeRole::Staff);
        $this->assertFalse($policy->requires($staff));
        $this->assertFalse(app(EmailOtpAuthenticationProvider::class)->isEnabled($staff));
    }

    public function test_privileged_user_cannot_disable_mandatory_mfa(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $owner->forceFill(['email_two_factor_enabled_at' => now()])->save();

        $this->expectException(ValidationException::class);
        app(TwoFactorService::class)->disableSelf($owner);
    }

    public function test_tenth_failed_password_attempt_locks_for_thirty_minutes_and_success_clear_is_supported(): void
    {
        $attempts = app(LoginAttemptService::class);
        $identifier = 'CaseSensitive@TechPointZone.com';
        $ip = '192.0.2.10';

        RateLimiter::clear('unrelated');
        foreach (range(1, 9) as $_) {
            $attempts->recordFailure($identifier, $ip, null, 'test');
            $this->assertFalse($attempts->isLocked(mb_strtolower($identifier), $ip));
        }

        $attempts->recordFailure($identifier, $ip, null, 'test');
        $this->assertTrue($attempts->isLocked(mb_strtolower($identifier), $ip));
        $this->assertGreaterThanOrEqual(29 * 60, $attempts->secondsRemaining($identifier, $ip));
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame(['channel' => 'test'], ActivityLog::query()->sole()->properties);

        $this->travel(31)->minutes();
        $this->assertFalse($attempts->isLocked($identifier, $ip));

        $attempts->recordFailure($identifier, $ip, null, 'test');
        $attempts->clear($identifier, $ip);
        $this->assertFalse($attempts->isLocked($identifier, $ip));
    }

    public function test_correct_web_password_cannot_bypass_lockout_and_success_clears_prior_failures(): void
    {
        $user = $this->user(EmployeeRole::Staff);
        $user->forceFill(['password' => 'ValidPassword1!'])->save();
        $attempts = app(LoginAttemptService::class);

        foreach (range(1, 10) as $_) {
            $attempts->recordFailure($user->email, '127.0.0.1', $user, 'web');
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'ValidPassword1!')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();

        $this->travel(31)->minutes();
        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'ValidPassword1!')
            ->call('authenticate')
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        foreach (range(1, 9) as $_) {
            $attempts->recordFailure($user->email, '127.0.0.1', $user, 'web');
        }
        $this->assertFalse($attempts->isLocked($user->email, '127.0.0.1'));
    }

    public function test_web_inactivity_policy_expires_session_without_applying_to_mobile_tokens(): void
    {
        $user = $this->user(EmployeeRole::Staff);

        Route::middleware(['web', EnforceWebSecurityPolicy::class])
            ->get('/_test/security-session', fn () => response()->json(['ok' => true]));

        $this->actingAs($user)
            ->withSession(['auth_security.last_activity_at' => now()->subMinutes(121)->timestamp])
            ->getJson('/_test/security-session')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_inactive');

        $token = $user->createToken('Security test')->plainTextToken;
        $this->withToken($token)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk();

        $this->assertSame(120, config('session.lifetime'));
    }

    public function test_expired_password_returns_machine_readable_mobile_response_without_revoking_token(): void
    {
        $user = $this->user(EmployeeRole::Staff);
        $user->forceFill(['password_changed_at' => now()->subDays(366)])->save();
        $token = $user->createToken('Security test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertStatus(428)
            ->assertJsonPath('code', 'password_rotation_required');

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
        $event = ActivityLog::query()->where('event', 'auth_security.password_rotation_required')->sole();
        $this->assertSame(['channel' => 'mobile_api'], $event->properties);
    }

    public function test_login_and_security_page_displays_fixed_policy_without_editable_security_values(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        $this->actingAs($owner)
            ->get('/admin/administration/login-security')
            ->assertOk()
            ->assertSee('Application Security Policy')
            ->assertSee('12 characters')
            ->assertSee('10 attempts / 30 minutes')
            ->assertSee('120 minutes');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create([
            'email' => fake()->unique()->userName().'@techpointzone.com',
            'password_changed_at' => now(),
        ]);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh()->load('employee');
    }
}
