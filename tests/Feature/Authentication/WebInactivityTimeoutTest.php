<?php

namespace Tests\Feature\Authentication;

use App\Enums\EmployeeRole;
use App\Http\Middleware\EnforceWebSecurityPolicy;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\WebInactivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebInactivityTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', EnforceWebSecurityPolicy::class])
            ->get('/_test/web-idle/html', fn () => response('<p>ERP</p>'));

        Route::middleware(['web', EnforceWebSecurityPolicy::class])
            ->get('/_test/web-idle/background', fn () => response()->json(['ok' => true]));
    }

    public function test_authenticated_user_remains_signed_in_before_two_hour_timeout(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(119)->timestamp])
            ->getJson('/_test/web-idle/background')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_more_than_two_hours_of_inactivity_logs_out_and_invalidates_session(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([
                WebInactivityService::SESSION_KEY => now()->subMinutes(121)->timestamp,
                'sensitive_test_value' => 'must-be-cleared',
            ])
            ->getJson('/_test/web-idle/background')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_inactive')
            ->assertJsonPath('redirect', route('filament.admin.auth.login'))
            ->assertSessionMissing('sensitive_test_value');

        $this->assertGuest();
    }

    public function test_explicit_genuine_activity_refreshes_the_server_timestamp(): void
    {
        $user = $this->user();
        $oldTimestamp = now()->subMinutes(90)->timestamp;

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => $oldTimestamp])
            ->postJson(route('auth.session.activity.store'), [], [
                'X-TPZ-User-Activity' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('expires_at', now()->addMinutes(120)->timestamp)
            ->assertSessionHas(WebInactivityService::SESSION_KEY, now()->timestamp);
    }

    public function test_activity_endpoint_does_not_accept_an_unmarked_automatic_request(): void
    {
        $user = $this->user();
        $oldTimestamp = now()->subMinutes(90)->timestamp;

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => $oldTimestamp])
            ->postJson(route('auth.session.activity.store'))
            ->assertBadRequest()
            ->assertSessionHas(WebInactivityService::SESSION_KEY, $oldTimestamp);
    }

    public function test_foreground_html_navigation_refreshes_activity(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => now()->subMinutes(90)->timestamp])
            ->withHeader('Accept', 'text/html')
            ->get('/_test/web-idle/html')
            ->assertOk()
            ->assertSessionHas(WebInactivityService::SESSION_KEY, now()->timestamp);
    }

    public function test_background_json_and_livewire_requests_do_not_refresh_genuine_activity(): void
    {
        $user = $this->user();
        $oldTimestamp = now()->subMinutes(90)->timestamp;

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => $oldTimestamp])
            ->getJson('/_test/web-idle/background')
            ->assertOk()
            ->assertSessionHas(WebInactivityService::SESSION_KEY, $oldTimestamp);

        $this->withHeader('Accept', 'text/html')
            ->withHeader('X-Livewire', 'true')
            ->get('/_test/web-idle/html')
            ->assertOk()
            ->assertSessionHas(WebInactivityService::SESSION_KEY, $oldTimestamp);

        $this->travel(31)->minutes();

        $this->getJson('/_test/web-idle/background')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_inactive');
    }

    public function test_status_endpoint_reports_expiry_without_refreshing_activity(): void
    {
        $user = $this->user();
        $lastActivityAt = now()->subMinutes(60)->timestamp;

        $this->actingAs($user)
            ->withSession([WebInactivityService::SESSION_KEY => $lastActivityAt])
            ->getJson(route('auth.session.activity.show'))
            ->assertOk()
            ->assertJsonPath('expires_at', $lastActivityAt + (120 * 60))
            ->assertSessionHas(WebInactivityService::SESSION_KEY, $lastActivityAt);
    }

    public function test_authenticated_filament_layout_contains_warning_and_stay_signed_in_control(): void
    {
        $user = $this->user(EmployeeRole::Owner);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Your session will expire due to inactivity in')
            ->assertSee('Stay Signed In')
            ->assertSee('tpz-inactivity-warning', escape: false)
            ->assertSee('data-navigate-once', escape: false)
            ->assertSee('pointerdown', escape: false)
            ->assertSee('visibilityState', escape: false);
    }

    private function user(EmployeeRole $role = EmployeeRole::Staff): User
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
