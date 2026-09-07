<?php

namespace Tests\Feature\Authentication;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeeRole;
use App\Exceptions\OtpChallengeException;
use App\Exceptions\OtpCooldownException;
use App\Filament\Auth\Login;
use App\Models\AuthenticationOtpChallenge;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\LoginSecuritySetting;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use App\Services\AuthenticationOtpService;
use App\Services\LoginBrandingService;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\TwoFactorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandedOtpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_branding_uses_dedicated_values_then_company_profile_fallback(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('company/logo.png', 'company-logo');
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Fallback Company', 'logo_path' => 'company/logo.png']);

        $fallback = app(LoginBrandingService::class)->presentation();
        $this->assertSame('Tech Point Zone ERP', $fallback['title']);
        $this->assertSame('company_profile', $fallback['logo_source']);

        Storage::disk('public')->put('login-branding/login.png', 'login-logo');
        LoginSecuritySetting::query()->create([
            'id' => 1,
            'login_title' => 'Secure ERP',
            'login_subtitle' => 'Company operations',
            'login_logo_path' => 'login-branding/login.png',
        ]);
        $dedicated = app(LoginBrandingService::class)->presentation();
        $this->assertSame('Secure ERP', $dedicated['title']);
        $this->assertSame('Company operations', $dedicated['subtitle']);
        $this->assertSame('login_settings', $dedicated['logo_source']);

        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('Secure ERP')->assertSee('Login with Email OTP');
    }

    public function test_email_otp_page_uses_shared_filament_auth_layout_and_dedicated_branding(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('company/legal.png', 'company-logo');
        Storage::disk('public')->put('login-branding/tpz.png', 'login-logo');
        CompanyProfile::query()->create([
            'id' => 1,
            'company_name_en' => 'Tech Point Zone Electronics Trading LLC',
            'logo_path' => 'company/legal.png',
        ]);
        LoginSecuritySetting::query()->create([
            'id' => 1,
            'login_title' => 'TPZ ERP',
            'login_subtitle' => 'Internal Business Management System',
            'login_logo_path' => 'login-branding/tpz.png',
        ]);

        $response = $this->get(route('auth.otp.request'))
            ->assertOk()
            ->assertSee('Tech Point Zone ERP')
            ->assertSee('Internal Business Management System')
            ->assertSee('Login with Email OTP')
            ->assertSee('Enter your company email and we’ll send you a verification code.')
            ->assertSee('Send Verification Code')
            ->assertSee('Back to Password Login')
            ->assertDontSee('Tech Point Zone Electronics Trading LLC')
            ->assertSee('data-auth-branding', false)
            ->assertSee('fi-simple-layout', false)
            ->assertSee('fi-input-wrp', false)
            ->assertSee('tpz-auth-submit', false)
            ->assertSee('style="width:100%;justify-content:center;"', false)
            ->assertSee(route('auth.branding.logo'), false);

        $response->assertDontSee('company/legal.png', false);
        $this->get(route('auth.branding.logo'))->assertOk();
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('data-auth-branding', false);
    }

    public function test_missing_logo_file_does_not_render_a_broken_image(): void
    {
        Storage::fake('public');
        CompanyProfile::query()->create([
            'id' => 1,
            'company_name_en' => 'Legal Company Name',
            'logo_path' => 'missing/company-logo.png',
        ]);

        $this->get(route('auth.otp.request'))
            ->assertOk()
            ->assertSee('Tech Point Zone ERP')
            ->assertDontSee('<img', false)
            ->assertDontSee('Legal Company Name');
    }

    public function test_login_otp_is_hashed_purpose_bound_and_authenticates_active_employee(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post(route('auth.otp.send'), ['email' => $user->employee->email])
            ->assertRedirect(route('auth.otp.verify'))
            ->assertSessionHas('auth_otp.login_challenge');

        $challenge = AuthenticationOtpChallenge::query()->sole();
        $notification = $this->sentOtp($user, AuthenticationOtpPurpose::Login);
        $this->assertTrue(Hash::check($notification->code, $challenge->code_hash));
        $this->assertNotSame($notification->code, $challenge->code_hash);
        $this->assertSame(AuthenticationOtpPurpose::Login, $challenge->purpose);
        $this->assertSame(5, AuthenticationOtpService::EXPIRY_MINUTES);
        $this->assertSame(60, AuthenticationOtpService::RESEND_COOLDOWN_SECONDS);
        $this->assertSame(5, (int) $challenge->created_at->diffInMinutes($challenge->expires_at));
        $this->assertSame(5, $notification->expiryMinutes);

        $this->withSession(['url.intended' => '/'])
            ->post(route('auth.otp.verify.submit'), ['code' => $notification->code])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($challenge->refresh()->consumed_at);

        $this->post(route('auth.otp.verify.submit'), ['code' => $notification->code])->assertRedirect('/');
    }

    public function test_verification_page_uses_six_digit_boxes_and_visible_resend_countdown(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = $this->user();
        $this->post(route('auth.otp.send'), ['email' => $user->email]);

        $response = $this->get(route('auth.otp.verify'))
            ->assertOk()
            ->assertSee('It expires in 5 minutes.')
            ->assertSee('Resend code in 60s')
            ->assertSee('name="code"', false)
            ->assertSee('aria-label="Digit 1 of 6"', false)
            ->assertSee('aria-label="Character 6 of 6"', false)
            ->assertSee('x-data="filamentOneTimeCodeInput"', false)
            ->assertSee('class="fi-one-time-code-input-ctn"', false)
            ->assertSee('class="fi-sr-only"', false);

        $this->assertSame(6, substr_count($response->getContent(), 'fi-one-time-code-input-digit'));
        $this->assertStringContainsString('.fi-one-time-code-input-ctn', file_get_contents(public_path('css/filament/filament/app.css')));

        $this->travel(17)->seconds();
        $this->get(route('auth.otp.verify'))->assertOk()->assertSee('Resend code in 43s');
        $this->post(route('auth.otp.resend'))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('authentication_otp_challenges', 1);

        $this->travel(44)->seconds();
        $this->get(route('auth.otp.verify'))->assertOk()->assertSee('remaining: 0', false);
        $this->post(route('auth.otp.resend'))->assertRedirect();
        $this->assertDatabaseCount('authentication_otp_challenges', 2);
        $this->get(route('auth.otp.verify'))->assertOk()->assertSee('Resend code in 60s');
    }

    public function test_public_authentication_pages_use_only_tpz_branding(): void
    {
        $user = $this->user();

        foreach ([route('filament.admin.auth.login'), route('auth.otp.request'), route('auth.password.request')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Tech Point Zone ERP')
                ->assertSee('Internal Business Management System')
                ->assertDontSee('Laravel');
        }

        $this->withSession(['auth_otp.password_reset_user' => $user->id])
            ->get(route('auth.password.reset'))
            ->assertOk()
            ->assertSee('Tech Point Zone ERP')
            ->assertDontSee('Laravel');
    }

    public function test_authentication_notification_is_sent_now_without_waiting_for_queue_worker(): void
    {
        Queue::fake();
        Mail::fake();
        Event::fake([NotificationSending::class]);
        $user = $this->user();

        app(AuthenticationOtpService::class)->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');

        Event::assertDispatched(NotificationSending::class);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_synchronous_smtp_failure_returns_safe_message_and_consumes_challenge(): void
    {
        $this->mock(EmailConfigurationService::class)
            ->shouldReceive('apply')
            ->once()
            ->with(false)
            ->andReturn(false);
        $user = $this->user();

        try {
            app(AuthenticationOtpService::class)->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
            $this->fail('SMTP failure was not surfaced.');
        } catch (OtpChallengeException $exception) {
            $this->assertSame('Unable to send the verification code. Please try again.', $exception->getMessage());
        }

        $this->assertNotNull(AuthenticationOtpChallenge::query()->sole()->consumed_at);
    }

    public function test_wrong_expired_used_and_cross_purpose_codes_are_rejected(): void
    {
        Notification::fake();
        $user = $this->user();
        $service = app(AuthenticationOtpService::class);
        $login = $service->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
        $loginCode = $this->sentOtp($user, AuthenticationOtpPurpose::Login)->code;

        $this->assertNull($service->verify($login->id, AuthenticationOtpPurpose::PasswordReset, $loginCode));
        $this->assertNull($service->verify($login->id, AuthenticationOtpPurpose::Login, '111111'));
        $this->assertSame($user->id, $service->verify($login->id, AuthenticationOtpPurpose::Login, $loginCode)?->id);
        $this->assertNull($service->verify($login->id, AuthenticationOtpPurpose::Login, $loginCode));

        $reset = $service->issue($user, AuthenticationOtpPurpose::PasswordReset, '127.0.0.1');
        $resetCode = $this->sentOtp($user, AuthenticationOtpPurpose::PasswordReset)->code;
        $reset->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->assertNull($service->verify($reset->id, AuthenticationOtpPurpose::PasswordReset, $resetCode));
    }

    public function test_resend_cooldown_and_five_attempt_ceiling_are_enforced(): void
    {
        Notification::fake();
        $user = $this->user();
        $service = app(AuthenticationOtpService::class);
        $challenge = $service->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');

        try {
            $service->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
            $this->fail('The resend cooldown was not enforced.');
        } catch (OtpCooldownException) {
            $this->assertTrue(true);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->verify($challenge->id, AuthenticationOtpPurpose::Login, '999999');
        }
        $this->assertNotNull($challenge->refresh()->consumed_at);
    }

    public function test_resend_after_cooldown_invalidates_the_previous_same_purpose_code(): void
    {
        Notification::fake();
        $user = $this->user();
        $service = app(AuthenticationOtpService::class);
        $first = $service->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
        $firstCode = $this->sentOtp($user, AuthenticationOtpPurpose::Login)->code;

        $this->travel(61)->seconds();
        $second = $service->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
        $this->assertNotNull($first->refresh()->consumed_at);
        $this->assertNull($service->verify($first->id, AuthenticationOtpPurpose::Login, $firstCode));
        $this->assertNull($second->consumed_at);
    }

    public function test_unknown_and_inactive_accounts_receive_generic_response_without_usable_challenge(): void
    {
        Notification::fake();
        $inactive = $this->user(active: false);

        $this->post(route('auth.otp.send'), ['email' => 'unknown@example.test'])
            ->assertRedirect(route('auth.otp.verify'))
            ->assertSessionHas('status', 'If the account is eligible, a verification code has been sent.');
        $this->post(route('auth.password.send'), ['email' => $inactive->employee->email])
            ->assertRedirect(route('auth.password.verify'));
        $this->assertDatabaseCount('authentication_otp_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_password_reset_uses_reset_purpose_changes_password_and_consumes_code(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->post(route('auth.password.send'), ['email' => $user->employee->email])->assertRedirect(route('auth.password.verify'));
        $code = $this->sentOtp($user, AuthenticationOtpPurpose::PasswordReset)->code;
        $this->post(route('auth.password.verify.submit'), ['code' => $code])->assertRedirect(route('auth.password.reset'));
        $this->post(route('auth.password.update'), [
            'password' => 'New-Secure-Password-2026!',
            'password_confirmation' => 'New-Secure-Password-2026!',
        ])->assertRedirect(route('filament.admin.auth.login'));

        $this->assertTrue(Hash::check('New-Secure-Password-2026!', $user->refresh()->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotNull(AuthenticationOtpChallenge::query()->sole()->consumed_at);
    }

    public function test_password_login_requires_email_otp_only_when_two_factor_is_enabled(): void
    {
        Notification::fake();
        $user = $this->user();
        $user->forceFill(['email_two_factor_enabled_at' => now()])->save();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->call('authenticate');

        $this->assertGuest();
        $this->assertNotNull($component->get('userUndertakingMultiFactorAuthentication'));
        $component->assertSee('Resend code in 60s');
        $code = $this->sentOtp($user, AuthenticationOtpPurpose::TwoFactor)->code;
        $component->set('data.multiFactor.company_email_otp.code', $code)
            ->call('authenticate')
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_password_login_still_works_and_inactive_employee_is_denied(): void
    {
        $active = $this->user();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withSession(['url.intended' => '/']);
        Livewire::test(Login::class)
            ->set('data.email', $active->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($active);

        auth()->logout();
        $inactive = $this->user(active: false);
        Livewire::test(Login::class)
            ->set('data.email', $inactive->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();
    }

    public function test_authenticated_erp_root_never_displays_the_laravel_welcome_page(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect('/admin');
    }

    public function test_employee_enables_two_factor_after_code_verification_and_disables_with_service(): void
    {
        Notification::fake();
        $user = $this->user();
        $challenge = app(AuthenticationOtpService::class)->issue($user, AuthenticationOtpPurpose::TwoFactor, '127.0.0.1');
        $code = $this->sentOtp($user, AuthenticationOtpPurpose::TwoFactor)->code;
        $verified = app(AuthenticationOtpService::class)->verify($challenge->id, AuthenticationOtpPurpose::TwoFactor, $code);
        $this->assertSame($user->id, $verified?->id);

        app(TwoFactorService::class)->enableSelf($user);
        $this->assertNotNull($user->email_two_factor_enabled_at);
        app(TwoFactorService::class)->disableSelf($user);
        $this->assertNull($user->email_two_factor_enabled_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth_security.two_factor_enabled', 'subject_type' => 'user']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth_security.two_factor_disabled', 'subject_type' => 'user']);
    }

    private function user(bool $active = true, EmployeeRole $role = EmployeeRole::Staff): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => $active]);

        return $user->refresh()->load('employee');
    }

    private function sentOtp(User $user, AuthenticationOtpPurpose $purpose): AuthenticationOtpNotification
    {
        $notification = null;
        Notification::assertSentTo($user, AuthenticationOtpNotification::class, function (AuthenticationOtpNotification $sent) use ($purpose, &$notification): bool {
            if ($sent->purpose !== $purpose) {
                return false;
            }
            $notification = $sent;

            return true;
        });

        return $notification;
    }
}
