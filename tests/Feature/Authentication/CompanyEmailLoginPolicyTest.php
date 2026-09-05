<?php

namespace Tests\Feature\Authentication;

use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeeRole;
use App\Filament\Auth\Login;
use App\Filament\Pages\Administration\LoginSecuritySettings;
use App\Filament\Pages\ChangeLoginEmail;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\AuthenticationOtpChallenge;
use App\Models\Employee;
use App\Models\LoginEmailChangeRequest;
use App\Models\LoginSecuritySetting;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use App\Services\AuthenticationOtpService;
use App\Services\CompanyEmailPolicyService;
use App\Services\EmployeeAccessService;
use App\Services\LoginEmailChangeService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyEmailLoginPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_company_domain_is_enforced_and_lookalikes_are_rejected(): void
    {
        $policy = app(CompanyEmailPolicyService::class);

        $this->assertTrue($policy->isAllowedEmail('Employee@TechPointZone.com'));
        $this->assertFalse($policy->isAllowedEmail('employee@sub.techpointzone.com'));
        $this->assertFalse($policy->isAllowedEmail('employee@techpointzone.com.example.test'));
        $this->assertFalse($policy->isAllowedEmail('employee@eviltechpointzone.com'));
    }

    public function test_allowed_domain_is_configurable_without_accepting_subdomains(): void
    {
        LoginSecuritySetting::query()->create([
            'id' => 1,
            'login_title' => 'TPZ ERP',
            'login_subtitle' => 'Internal Business Management System',
            'allowed_login_email_domain' => 'Example.Company',
        ]);
        $policy = app(CompanyEmailPolicyService::class);

        $this->assertSame('example.company', $policy->allowedDomain());
        $this->assertTrue($policy->isAllowedEmail('person@example.company'));
        $this->assertFalse($policy->isAllowedEmail('person@sub.example.company'));
    }

    public function test_external_staff_is_denied_by_password_and_public_otp_without_mutation(): void
    {
        Notification::fake();
        $user = $this->user('legacy-staff@example.test');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $user->email)
            ->set('data.password', 'password')
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
        $this->assertGuest();
        $this->post(route('auth.otp.send'), ['email' => $user->email])
            ->assertSessionHas('status', 'If the account is eligible, a verification code has been sent.');
        $this->assertDatabaseCount('authentication_otp_challenges', 0);
        $this->assertSame('legacy-staff@example.test', $user->refresh()->email);
    }

    public function test_authentication_uses_user_email_as_authoritative_address(): void
    {
        Notification::fake();
        $user = $this->user('login@techpointzone.com', EmployeeRole::Staff, 'contact@example.test');

        $this->assertSame($user->id, app(AuthenticationOtpService::class)->eligibleUserForCompanyEmail('LOGIN@TECHPOINTZONE.COM')?->id);
        $this->assertNull(app(AuthenticationOtpService::class)->eligibleUserForCompanyEmail('contact@example.test'));
        app(AuthenticationOtpService::class)->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');
        Notification::assertSentTo($user, AuthenticationOtpNotification::class);
        $this->assertSame('login@techpointzone.com', $user->routeNotificationForMail(new AuthenticationOtpNotification('123456', AuthenticationOtpPurpose::Login)));
    }

    public function test_security_settings_and_controlled_change_page_render_required_policy_state(): void
    {
        $owner = $this->user('owner@techpointzone.com', EmployeeRole::Owner);

        $this->actingAs($owner)
            ->get(LoginSecuritySettings::getUrl())
            ->assertOk()
            ->assertSee('Allowed Login Email Domain')
            ->assertSee('techpointzone.com');
        $this->get(ChangeLoginEmail::getUrl())
            ->assertOk()
            ->assertSee('Current Login Email')
            ->assertSee('Send Code to Current Email')
            ->assertSee('owner@techpointzone.com');
    }

    public function test_employee_edit_exposes_authorized_secure_login_email_change_action_and_status(): void
    {
        $owner = $this->user('adiljaved104@gmail.com', EmployeeRole::Owner);

        $this->actingAs($owner)
            ->get(EmployeeResource::getUrl('edit', ['record' => $owner->employee]))
            ->assertOk()
            ->assertSee('Linked Login Account')
            ->assertSee('Current Login Email')
            ->assertSee('adiljaved104@gmail.com')
            ->assertSee('Company Email Status')
            ->assertSee('Update Required')
            ->assertSee('2FA Status')
            ->assertSee('Change Login Email');
    }

    public function test_dual_otp_change_verifies_both_mailboxes_and_commits_once(): void
    {
        Notification::fake();
        $user = $this->user('old@techpointzone.com', EmployeeRole::Staff, 'contact@techpointzone.com');
        $service = app(LoginEmailChangeService::class);
        $request = $service->start($user, $user, '127.0.0.1');
        $currentCode = $this->userOtp($user, AuthenticationOtpPurpose::EmailChangeCurrent);
        $request = $service->verifyCurrent($request, $currentCode, $user);
        $request = $service->sendNewEmailCode($request, 'new@techpointzone.com', $user, '127.0.0.1');
        $this->assertSame('old@techpointzone.com', $user->refresh()->email);
        $newCode = $this->routedOtp('new@techpointzone.com', AuthenticationOtpPurpose::EmailChangeNew);
        $service->complete($request, $newCode, $user, null);

        $this->assertSame('new@techpointzone.com', $user->refresh()->email);
        $this->assertSame('contact@techpointzone.com', $user->employee->refresh()->email);
        $this->assertSame($user->id, $user->employee->user_id);
        $this->assertSame(EmployeeRole::Staff, $user->employee->role);
        $this->assertNotNull(LoginEmailChangeRequest::query()->sole()->completed_at);
        $this->assertDatabaseHas('activity_logs', ['event' => 'auth_security.login_email_changed', 'subject_type' => 'user', 'subject_id' => $user->id]);
        $this->assertFalse(DB::table('activity_logs')->where('properties', 'like', '%'.$currentCode.'%')->exists());
        $this->assertFalse(DB::table('activity_logs')->where('properties', 'like', '%'.$newCode.'%')->exists());
    }

    public function test_email_change_otp_steps_show_authoritative_resend_countdowns(): void
    {
        Notification::fake();
        $this->freezeTime();
        $owner = $this->user('owner@techpointzone.com', EmployeeRole::Owner);

        $component = Livewire::withQueryParams(['employee' => $owner->employee->id])
            ->actingAs($owner)
            ->test(ChangeLoginEmail::class)
            ->call('start')
            ->assertSet('step', 'current')
            ->assertSet('currentResendSeconds', 60)
            ->assertSee('Resend code in 60s')
            ->assertSee('Verify Current Email')
            ->assertSee('Verifying...');

        $component
            ->set('currentCode', $this->userOtp($owner, AuthenticationOtpPurpose::EmailChangeCurrent))
            ->call('verifyCurrent')
            ->assertHasNoErrors()
            ->assertSet('step', 'new')
            ->assertSee('New Company Login Email')
            ->set('newEmail', 'new-owner@techpointzone.com')
            ->call('sendNewCode')
            ->assertSet('step', 'verify-new')
            ->assertSet('newResendSeconds', 60)
            ->assertSee('Resend code in 60s');

        $this->assertSame('owner@techpointzone.com', $owner->refresh()->email);
        $this->assertNotNull(LoginEmailChangeRequest::query()->sole()->current_verified_at);
    }

    public function test_shared_otp_boxes_entangle_directly_with_the_livewire_property(): void
    {
        app('view')->share('errors', new ViewErrorBag);
        $markup = Blade::render('<x-auth.otp-code-input name="currentCode" model="currentCode" label="Current Email Verification Code" />');

        $this->assertStringContainsString("\$wire.entangle('currentCode').live", $markup);
        $this->assertStringNotContainsString('wire:model.live="currentCode"', $markup);
    }

    public function test_current_email_verification_surfaces_blank_wrong_and_expired_codes(): void
    {
        Notification::fake();

        $blankUser = $this->user('blank@techpointzone.com');
        Livewire::withQueryParams(['employee' => $blankUser->employee->id])
            ->actingAs($blankUser)
            ->test(ChangeLoginEmail::class)
            ->call('start')
            ->call('verifyCurrent')
            ->assertHasErrors(['currentCode' => 'required'])
            ->assertSet('step', 'current');

        $wrongUser = $this->user('wrong@techpointzone.com');
        $wrongComponent = Livewire::withQueryParams(['employee' => $wrongUser->employee->id])
            ->actingAs($wrongUser)
            ->test(ChangeLoginEmail::class)
            ->call('start');
        $validCode = $this->userOtp($wrongUser, AuthenticationOtpPurpose::EmailChangeCurrent);
        $wrongCode = ($validCode[0] === '9' ? '8' : '9').substr($validCode, 1);
        $wrongComponent->set('currentCode', $wrongCode)
            ->call('verifyCurrent')
            ->assertHasErrors(['currentCode'])
            ->assertSee('The verification code is incorrect.')
            ->assertSet('step', 'current');

        $expiredUser = $this->user('expired@techpointzone.com');
        $expiredComponent = Livewire::withQueryParams(['employee' => $expiredUser->employee->id])
            ->actingAs($expiredUser)
            ->test(ChangeLoginEmail::class)
            ->call('start');
        $expiredCode = $this->userOtp($expiredUser, AuthenticationOtpPurpose::EmailChangeCurrent);
        AuthenticationOtpChallenge::query()->where('user_id', $expiredUser->id)
            ->where('purpose', AuthenticationOtpPurpose::EmailChangeCurrent->value)
            ->update(['expires_at' => now()->subSecond()]);
        $expiredComponent->set('currentCode', $expiredCode)
            ->call('verifyCurrent')
            ->assertHasErrors(['currentCode'])
            ->assertSee('Verification code has expired. Please request a new code.')
            ->assertSet('step', 'current');
    }

    public function test_current_email_verification_is_idempotent_after_success(): void
    {
        Notification::fake();
        $owner = $this->user('owner@techpointzone.com', EmployeeRole::Owner);
        $service = app(LoginEmailChangeService::class);
        $request = $service->start($owner, $owner, '127.0.0.1');
        $code = $this->userOtp($owner, AuthenticationOtpPurpose::EmailChangeCurrent);

        $verified = $service->verifyCurrent($request, $code, $owner);
        $retried = $service->verifyCurrent($verified->refresh(), $code, $owner);

        $this->assertNotNull($retried->current_verified_at);
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'auth_security.current_login_email_verified')->count());
        $this->assertSame('owner@techpointzone.com', $owner->refresh()->email);
    }

    public function test_new_email_must_be_approved_unique_and_current_mailbox_verified_first(): void
    {
        Notification::fake();
        $user = $this->user('first@techpointzone.com');
        $this->user('used@techpointzone.com');
        $service = app(LoginEmailChangeService::class);
        $request = $service->start($user, $user, '127.0.0.1');

        try {
            $service->sendNewEmailCode($request, 'new@techpointzone.com', $user, '127.0.0.1');
            $this->fail('Current mailbox verification was bypassed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $request = $service->verifyCurrent($request, $this->userOtp($user, AuthenticationOtpPurpose::EmailChangeCurrent), $user);
        foreach (['new@example.test', 'used@techpointzone.com'] as $email) {
            try {
                $service->sendNewEmailCode($request, $email, $user, '127.0.0.1');
                $this->fail('An invalid new login email was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame('first@techpointzone.com', $user->refresh()->email);
    }

    public function test_admin_cannot_change_protected_owner_but_owner_can_manage_staff(): void
    {
        $owner = $this->user('owner@techpointzone.com', EmployeeRole::Owner);
        $admin = $this->user('admin@techpointzone.com', EmployeeRole::Admin);
        $staff = $this->user('staff@techpointzone.com');
        $service = app(LoginEmailChangeService::class);

        $this->assertTrue($service->allows($owner, $staff));
        $this->assertFalse($service->allows($admin, $owner));
        $this->expectException(AuthorizationException::class);
        $service->authorize($admin, $owner);
    }

    public function test_staff_cannot_change_another_employee_and_admin_start_still_targets_current_mailbox(): void
    {
        Notification::fake();
        $owner = $this->user('owner@techpointzone.com', EmployeeRole::Owner);
        $admin = $this->user('admin@techpointzone.com', EmployeeRole::Admin);
        $staff = $this->user('staff@techpointzone.com');
        $other = $this->user('other@techpointzone.com');
        $service = app(LoginEmailChangeService::class);

        $this->assertFalse($service->allows($staff, $other));
        $request = $service->start($admin, $staff, '127.0.0.1');
        $this->assertSame($staff->id, $request->user_id);
        $this->userOtp($staff, AuthenticationOtpPurpose::EmailChangeCurrent);
        Notification::assertNotSentTo($admin, AuthenticationOtpNotification::class);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'auth_security.management_login_email_change_started',
            'actor_user_id' => $admin->id,
            'subject_id' => $staff->id,
        ]);
        $this->assertTrue($service->allows($owner, $other));
    }

    public function test_successful_self_change_revokes_remember_token_and_other_database_sessions(): void
    {
        Notification::fake();
        config()->set('session.driver', 'database');
        config()->set('session.table', 'sessions');
        $user = $this->user('old@techpointzone.com');
        $oldRemember = 'existing-remember-token';
        $user->forceFill(['remember_token' => $oldRemember])->save();
        foreach (['keep-current', 'remove-other'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => null,
                'user_agent' => null,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $service = app(LoginEmailChangeService::class);
        $request = $service->start($user, $user, '127.0.0.1');
        $request = $service->verifyCurrent($request, $this->userOtp($user, AuthenticationOtpPurpose::EmailChangeCurrent), $user);
        $request = $service->sendNewEmailCode($request, 'new@techpointzone.com', $user, '127.0.0.1');
        $service->complete($request, $this->routedOtp('new@techpointzone.com', AuthenticationOtpPurpose::EmailChangeNew), $user, 'keep-current');

        $this->assertDatabaseHas('sessions', ['id' => 'keep-current', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'remove-other']);
        $this->assertNotSame($oldRemember, $user->refresh()->remember_token);
    }

    public function test_legacy_initial_owner_transition_is_narrow_and_permanently_closes_after_change(): void
    {
        Notification::fake();
        $owner = $this->user('legacy-owner@example.test', EmployeeRole::Owner);
        $this->assertSame(EmployeeAccessService::INITIAL_OWNER_USER_ID, $owner->id);
        $policy = app(CompanyEmailPolicyService::class);
        $this->assertTrue($policy->allowsAuthentication($owner));
        $service = app(LoginEmailChangeService::class);
        $request = $service->start($owner, $owner, '127.0.0.1');
        $request = $service->verifyCurrent($request, $this->userOtp($owner, AuthenticationOtpPurpose::EmailChangeCurrent), $owner);
        $request = $service->sendNewEmailCode($request, 'owner@techpointzone.com', $owner, '127.0.0.1');
        $service->complete($request, $this->routedOtp('owner@techpointzone.com', AuthenticationOtpPurpose::EmailChangeNew), $owner, null);

        $this->assertNotNull(LoginSecuritySetting::query()->find(1)?->legacy_owner_email_transition_completed_at);
        $owner->forceFill(['email' => 'external-again@example.test'])->save();
        $this->assertFalse($policy->allowsAuthentication($owner->refresh()));
    }

    private function user(string $email, EmployeeRole $role = EmployeeRole::Staff, ?string $employeeEmail = null): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('password')]);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $employeeEmail ?? fake()->unique()->userName().'@techpointzone.com',
            'status' => true,
        ]);

        return $user->refresh()->load('employee');
    }

    private function userOtp(User $user, AuthenticationOtpPurpose $purpose): string
    {
        $code = '';
        Notification::assertSentTo($user, AuthenticationOtpNotification::class, function (AuthenticationOtpNotification $notification) use ($purpose, &$code): bool {
            if ($notification->purpose !== $purpose) {
                return false;
            }
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    private function routedOtp(string $email, AuthenticationOtpPurpose $purpose): string
    {
        $code = '';
        Notification::assertSentOnDemand(AuthenticationOtpNotification::class, function (AuthenticationOtpNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, $purpose, &$code): bool {
            if ($notification->purpose !== $purpose || $notifiable->routes['mail'] !== $email) {
                return false;
            }
            $code = $notification->code;

            return true;
        });

        return $code;
    }
}
