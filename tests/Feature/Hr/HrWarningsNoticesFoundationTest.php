<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HrWarningsNoticesFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_tables_are_empty_and_reference_sequences_are_ready(): void
    {
        foreach (['warning_categories', 'employee_warnings', 'hr_notices', 'hr_notice_recipients', 'hr_acknowledgments'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('reference_sequences', ['key' => 'employee_warning:2026', 'next_value' => 1]);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'hr_notice:2026', 'next_value' => 1]);

        $sequences = app(ReferenceSequenceService::class);
        $this->assertSame('WRN-2026-000001', $sequences->nextEmployeeWarningReference(2026));
        $this->assertSame('NTC-2026-000001', $sequences->nextHrNoticeReference(2026));
    }

    public function test_warning_history_is_constrained_and_does_not_change_attendance_or_performance_facts(): void
    {
        [$owner, $staff] = $this->people();
        $attendanceCount = DB::table('employee_attendances')->count();
        $activityCount = DB::table('activity_logs')->count();
        $categoryId = DB::table('warning_categories')->insertGetId([
            'name' => 'Conduct', 'normalized_name' => 'conduct', 'status' => true,
            'created_by_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_warnings')->insert([
            'reference' => 'WRN-2026-009999', 'employee_id' => $staff->employee->id,
            'warning_category_id' => $categoryId, 'warning_level' => 'written',
            'title' => 'Documented conduct concern', 'description' => 'A factual HR record entered by an authorized operator.',
            'issued_date' => '2026-08-21', 'issued_by_user_id' => $owner->id,
            'acknowledgment_required' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($attendanceCount, DB::table('employee_attendances')->count());
        $this->assertSame($activityCount, DB::table('activity_logs')->count());
        $this->expectException(QueryException::class);
        DB::table('employees')->where('id', $staff->employee->id)->delete();
    }

    public function test_notice_audience_and_explicit_acknowledgment_constraints_preserve_history(): void
    {
        [$owner, $staff, $team] = $this->people();
        $noticeId = DB::table('hr_notices')->insertGetId([
            'reference' => 'NTC-2026-009999', 'title' => 'Team operating notice',
            'content' => 'The exact published content.', 'audience_type' => 'team', 'team_id' => $team->id,
            'priority' => 'important', 'published_at' => now(), 'acknowledgment_required' => true,
            'published_by_user_id' => $owner->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hr_notice_recipients')->insert([
            'hr_notice_id' => $noticeId, 'employee_id' => $staff->employee->id, 'created_at' => now(),
        ]);
        DB::table('hr_acknowledgments')->insert([
            'hr_notice_id' => $noticeId, 'employee_id' => $staff->employee->id,
            'read_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('hr_acknowledgments', [
            'hr_notice_id' => $noticeId, 'employee_id' => $staff->employee->id, 'acknowledged_at' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('hr_notice_recipients')->insert([
            'hr_notice_id' => $noticeId, 'employee_id' => $staff->employee->id, 'created_at' => now(),
        ]);
    }

    public function test_acknowledgment_requires_an_immutable_content_snapshot_and_is_unique(): void
    {
        [$owner, $staff] = $this->people();
        $categoryId = DB::table('warning_categories')->insertGetId([
            'name' => 'Other', 'normalized_name' => 'other', 'status' => true,
            'created_by_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warningId = DB::table('employee_warnings')->insertGetId([
            'reference' => 'WRN-2026-009998', 'employee_id' => $staff->employee->id,
            'warning_category_id' => $categoryId, 'warning_level' => 'verbal', 'title' => 'Recorded warning',
            'description' => 'Recorded warning details.', 'issued_date' => '2026-08-21',
            'issued_by_user_id' => $owner->id, 'acknowledgment_required' => true,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            DB::table('hr_acknowledgments')->insert([
                'employee_warning_id' => $warningId, 'employee_id' => $staff->employee->id,
                'acknowledged_at' => now(), 'acknowledged_by_user_id' => $staff->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('An acknowledgment without its immutable content snapshot was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        DB::table('hr_acknowledgments')->insert([
            'employee_warning_id' => $warningId, 'employee_id' => $staff->employee->id,
            'read_at' => now(), 'acknowledged_at' => now(), 'acknowledged_by_user_id' => $staff->id,
            'content_snapshot' => 'Recorded warning|Recorded warning details.',
            'content_hash' => hash('sha256', 'Recorded warning|Recorded warning details.'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseCount('hr_acknowledgments', 1);

        $this->expectException(QueryException::class);
        DB::table('hr_acknowledgments')->insert([
            'employee_warning_id' => $warningId, 'employee_id' => $staff->employee->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_role_defaults_keep_sensitive_warning_actions_protected_and_allow_explicit_manager_grant(): void
    {
        [$owner, $staff, , $admin, $manager] = $this->people();
        $authorization = app(HrAuthorization::class);

        $this->assertTrue($authorization->allows($owner, HrPermission::WarningManage), 'Owner default');
        $this->assertTrue($authorization->allows($admin, HrPermission::WarningIssue), 'Admin default');
        $this->assertTrue($authorization->allows($staff, HrPermission::WarningViewOwn), 'Staff own Warning default');
        $this->assertTrue($authorization->allows($staff, HrPermission::NoticeView), 'Staff Notice default');
        $this->assertFalse($authorization->allows($staff, HrPermission::WarningIssue));
        $this->assertFalse($authorization->allows($manager, HrPermission::WarningViewTeam));

        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id,
            'permission_key' => HrPermission::WarningViewTeam->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Explicit Team HR scope for focused foundation test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertTrue($authorization->allows($manager, HrPermission::WarningViewTeam));
    }

    /** @return array{User, User, Team, User, User} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $team);
        $staff = $this->user(EmployeeRole::Staff, $team);
        $admin = $this->user(EmployeeRole::Admin, $team);
        $manager = $this->user(EmployeeRole::Manager, $team);

        return [$owner, $staff, $team, $admin, $manager];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }
}
