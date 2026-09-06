<?php

namespace Tests\Feature\DemoData;

use App\Models\EmailSetting;
use App\Services\DemoData\DemoEnvironmentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoEnvironmentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'demo.enabled' => true,
            'demo.allowed_database' => ':memory:',
            'demo.password' => 'Test-only strong demo password 2026!',
            'mail.notifications_enabled' => false,
        ]);
    }

    public function test_production_uat_and_unknown_environments_are_denied(): void
    {
        foreach (['production', 'uat', 'preview'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);
            try {
                app(DemoEnvironmentGuard::class)->assertAllowed(false, 'STAGING-DEMO');
                $this->fail("The {$environment} environment was not denied.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('denied', $exception->getMessage());
            }
        }
    }

    public function test_feature_flag_exact_database_password_and_confirmation_are_required(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        config(['demo.enabled' => false]);
        $this->expectExceptionMessage('Demo data is disabled');
        app(DemoEnvironmentGuard::class)->assertAllowed(false, 'STAGING-DEMO');
    }

    public function test_database_mismatch_is_denied(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        config(['demo.allowed_database' => 'tpz_erp_staging']);
        $this->expectExceptionMessage('does not match');
        app(DemoEnvironmentGuard::class)->assertAllowed(false, 'STAGING-DEMO');
    }

    public function test_password_is_required_for_persistent_generation(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        config(['demo.password' => null]);
        $this->expectExceptionMessage('ERP_DEMO_USER_PASSWORD');
        app(DemoEnvironmentGuard::class)->assertAllowed(false, 'STAGING-DEMO');
    }

    public function test_confirmation_is_required_outside_the_automated_test_bypass(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        config(['demo.require_confirmation_in_tests' => true]);
        $this->expectExceptionMessage('confirmation token');
        app(DemoEnvironmentGuard::class)->assertAllowed(false, null);
    }

    public function test_enabled_outbound_email_blocks_persistent_generation(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        EmailSetting::query()->create([
            'id' => 1, 'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'demo@example.test',
            'smtp_password_encrypted' => 'test-only-password',
            'from_email' => 'notifications@techpointzone.com', 'from_name' => 'TPZ ERP',
        ]);
        $this->expectExceptionMessage('Outbound email must be disabled');
        app(DemoEnvironmentGuard::class)->assertAllowed(false, 'STAGING-DEMO');
    }

    public function test_dry_run_does_not_require_password_or_confirmation_and_writes_nothing(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');
        config(['demo.password' => null]);
        app(DemoEnvironmentGuard::class)->assertAllowed(true, null);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}
