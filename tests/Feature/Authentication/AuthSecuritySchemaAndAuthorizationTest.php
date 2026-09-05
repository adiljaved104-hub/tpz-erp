<?php

namespace Tests\Feature\Authentication;

use App\Enums\AuthSecurityPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\AuthSecurityAuthorization;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\TwoFactorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSecuritySchemaAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimal_schema_has_purpose_constraints_and_no_business_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('authentication_otp_challenges', [
            'id', 'user_id', 'purpose', 'code_hash', 'expires_at', 'consumed_at', 'attempts', 'requested_ip_hash',
        ]));
        $this->assertTrue(Schema::hasColumns('login_security_settings', ['login_logo_path', 'login_title', 'login_subtitle', 'allowed_login_email_domain', 'legacy_owner_email_transition_completed_at']));
        $this->assertTrue(Schema::hasColumns('login_email_change_requests', ['user_id', 'initiated_by_user_id', 'current_email', 'new_email', 'current_verified_at', 'new_verified_at', 'completed_at', 'cancelled_at']));
        $this->assertTrue(Schema::hasColumn('users', 'email_two_factor_enabled_at'));
        $this->assertDatabaseCount('authentication_otp_challenges', 0);
        $this->assertDatabaseCount('login_security_settings', 0);

        $this->expectException(QueryException::class);
        DB::table('authentication_otp_challenges')->insert([
            'id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'purpose' => 'wrong', 'code_hash' => 'not-a-code', 'expires_at' => now(), 'attempts' => 0,
        ]);
    }

    public function test_owner_admin_defaults_and_employee_overrides_are_respected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(AuthSecurityAuthorization::class);

        $this->assertTrue($authorization->allows($owner, AuthSecurityPermission::ManageTwoFactor));
        $this->assertTrue($authorization->allows($admin, AuthSecurityPermission::Manage));
        $this->assertFalse($authorization->allows($staff, AuthSecurityPermission::View));
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            AuthSecurityPermission::View->value,
            EmployeePermissionEffect::Allow,
            'Focused security test',
            $owner,
        );
        $this->assertTrue($authorization->allows($staff, AuthSecurityPermission::View));
    }

    public function test_admin_can_disable_staff_two_factor_but_cannot_weaken_owner(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $staff->forceFill(['email_two_factor_enabled_at' => now()])->save();
        $owner->forceFill(['email_two_factor_enabled_at' => now()])->save();

        app(TwoFactorService::class)->disableForManagement($staff, $admin);
        $this->assertNull($staff->email_two_factor_enabled_at);

        $this->expectException(AuthorizationException::class);
        app(TwoFactorService::class)->disableForManagement($owner, $admin);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh()->load('employee');
    }
}
