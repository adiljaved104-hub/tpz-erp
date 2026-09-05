<?php

namespace Tests\Feature\Production;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_check_is_read_only_and_sanitized(): void
    {
        $before = [
            'users' => User::query()->count(),
            'notifications' => DB::table('notifications')->count(),
        ];

        $this->artisan('erp:production-check')
            ->expectsOutputToContain('Application environment')
            ->expectsOutputToContain('Database driver')
            ->expectsOutputToContain('Backup location')
            ->expectsOutputToContain('Latest successful backup')
            ->expectsOutputToContain('Pending migrations')
            ->assertSuccessful();

        $this->assertSame($before['users'], User::query()->count());
        $this->assertSame($before['notifications'], DB::table('notifications')->count());
    }

    public function test_production_template_contains_required_safe_settings(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));

        $this->assertStringContainsString('APP_ENV=production', $template);
        $this->assertStringContainsString('APP_DEBUG=false', $template);
        $this->assertStringContainsString('APP_TIMEZONE=UTC', $template);
        $this->assertStringContainsString('DB_CONNECTION=mysql', $template);
        $this->assertStringContainsString('DB_TIMEZONE=+00:00', $template);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $template);
        $this->assertStringContainsString('DB_QUEUE_AFTER_COMMIT=true', $template);
        $this->assertStringContainsString('QUEUE_STALE_AFTER=600', $template);
        $this->assertMatchesRegularExpression('/^DB_PASSWORD=\s*$/m', $template);
        $this->assertMatchesRegularExpression('/^MAIL_PASSWORD=\s*$/m', $template);
        $this->assertDoesNotMatchRegularExpression('/APP_KEY=base64:[A-Za-z0-9+\/=]+/', $template);
    }
}
