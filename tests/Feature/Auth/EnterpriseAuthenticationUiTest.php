<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseAuthenticationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_keeps_filament_password_auth_and_enterprise_branding(): void
    {
        $response = $this->get(route('filament.admin.auth.login'));

        $response->assertOk()
            ->assertSee('Welcome Back')
            ->assertSee('One Platform.')
            ->assertSee('Complete Business Control.')
            ->assertSee('Login with Email OTP')
            ->assertSee('Sign In');
    }

    public function test_passwordless_login_and_password_reset_requests_use_branded_shell(): void
    {
        $this->get(route('auth.otp.request'))
            ->assertOk()
            ->assertSee('Login with Email OTP')
            ->assertSee('One Platform.');

        $this->get(route('auth.password.request'))
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('One Platform.');
    }

    public function test_verification_page_uses_existing_code_form_and_masked_submitted_email(): void
    {
        $this->withSession([
            'auth_otp.login_challenge' => (string) Str::uuid(),
            'auth_otp.login_display_email' => 'ad***@example.com',
        ])->get(route('auth.otp.verify'))
            ->assertOk()
            ->assertSee('Enter Verification Code')
            ->assertSee('ad***@example.com')
            ->assertSee('Verify Code')
            ->assertSee('Back to Login')
            ->assertSee('name="code"', false);
    }
}
