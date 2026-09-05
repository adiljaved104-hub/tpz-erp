<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\HrAcknowledgment;
use App\Models\Team;
use App\Models\User;
use App\Models\WarningCategory;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class HrWarningsNoticesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_issue_read_and_explicit_acknowledgment_are_secure_and_immutable(): void
    {
        [$owner, $staff] = $this->people();
        $category = WarningCategory::query()->create(['name' => 'Conduct', 'status' => true, 'created_by_user_id' => $owner->id]);
        $attendanceCount = DB::table('employee_attendances')->count();

        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id,
            'warning_level' => 'written',
            'warning_category_id' => $category->id,
            'title' => 'Documented concern',
            'description' => 'The exact warning content shown to the Employee.',
            'issued_date' => '2026-08-21',
            'acknowledgment_required' => true,
        ], $owner);

        $this->assertSame('WRN-2026-000001', $warning->reference);
        $this->assertDatabaseCount('employee_warnings', 1);
        $this->assertDatabaseCount('hr_acknowledgments', 1);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $staff->id, 'type' => 'warning.issued']);
        $this->assertSame($attendanceCount, DB::table('employee_attendances')->count());

        app(EmployeeWarningService::class)->markRead($warning, $staff);
        $ack = HrAcknowledgment::query()->firstOrFail();
        $this->assertNotNull($ack->read_at);
        $this->assertNull($ack->acknowledged_at, 'Opening a Warning must not acknowledge it.');

        app(EmployeeWarningService::class)->acknowledge($warning, $staff);
        $ack->refresh();
        $this->assertNotNull($ack->acknowledged_at);
        $this->assertSame(hash('sha256', $ack->content_snapshot), $ack->content_hash);
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning.issued']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning.acknowledged']);

        try {
            $warning->forceFill(['title' => 'Silently changed'])->save();
            $this->fail('Acknowledged Warning content was editable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $staff->employee->update(['status' => false]);
        $staff->unsetRelation('employee');
        $notification = $staff->notifications()->where('type', 'warning.issued')->firstOrFail();
        $this->assertFalse(app(NotificationInboxService::class)->open($staff, $notification->id)['available']);
    }

    public function test_warning_and_notice_scope_is_enforced_on_services_and_direct_resource_urls(): void
    {
        [$owner, $staff, $manager, $other, $team] = $this->people();
        $category = WarningCategory::query()->create(['name' => 'Attendance', 'status' => true, 'created_by_user_id' => $owner->id]);
        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id, 'warning_level' => 'verbal', 'warning_category_id' => $category->id,
            'title' => 'Private Employee warning', 'description' => 'Private warning details.', 'issued_date' => '2026-08-21',
            'acknowledgment_required' => false,
        ], $owner);

        $this->actingAs($staff)->get('/admin/employee-warnings/'.$warning->id)->assertOk();
        $this->actingAs($other)->get('/admin/employee-warnings/'.$warning->id)->assertForbidden();

        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => HrPermission::WarningViewTeam->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Team HR supervision test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertTrue(app(HrRecordAuthorization::class)->canViewWarning($manager, $warning));
        $unrelated = app(EmployeeWarningService::class)->issue([
            'employee_id' => $other->employee->id, 'warning_level' => 'written', 'warning_category_id' => $category->id,
            'title' => 'Unrelated team warning', 'description' => 'Must not appear in the Manager Team scope.', 'issued_date' => '2026-08-21',
            'acknowledgment_required' => false,
        ], $owner);
        $this->actingAs($manager)->get('/admin/employee-warnings')->assertOk()->assertSee($warning->reference)->assertDontSee($unrelated->reference);

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'team', 'team_id' => $team->id, 'employee_ids' => [],
            'title' => 'Team notice', 'content' => 'Stable team audience content.', 'priority' => 'normal',
            'published_at' => '2026-08-21 10:00:00', 'expires_at' => null, 'acknowledgment_required' => true,
        ], $owner);
        $this->actingAs($staff)->get('/admin/hr-notices/'.$notice->id)->assertOk();
        $this->actingAs($other)->get('/admin/hr-notices/'.$notice->id)->assertForbidden();
        $staff->employee->update(['team_id' => Team::query()->create(['name' => 'Transferred Team', 'status' => true])->id]);
        $staff->unsetRelation('employee');
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $notice->id, 'employee_id' => $staff->employee->id]);
        $this->actingAs($staff)->get('/admin/hr-notices/'.$notice->id)->assertOk();
    }

    public function test_notice_materializes_recipients_and_notification_read_is_not_hr_acknowledgment(): void
    {
        [$owner, $staff, , $other, $team] = $this->people();
        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected', 'team_id' => null, 'employee_ids' => [$staff->employee->id],
            'title' => 'Selected notice', 'content' => 'Exact selected audience notice.', 'priority' => 'important',
            'published_at' => '2026-08-21 10:00:00', 'expires_at' => '2026-08-25 10:00:00', 'acknowledgment_required' => true,
        ], $owner);

        $this->assertSame('NTC-2026-000001', $notice->reference);
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $notice->id, 'employee_id' => $staff->employee->id]);
        $this->assertDatabaseMissing('hr_notice_recipients', ['hr_notice_id' => $notice->id, 'employee_id' => $other->employee->id]);

        $notification = $staff->notifications()->where('type', 'notice.published')->firstOrFail();
        $result = app(NotificationInboxService::class)->open($staff, $notification->id);
        $this->assertTrue($result['available']);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull(HrAcknowledgment::query()->where('hr_notice_id', $notice->id)->value('acknowledged_at'));

        app(HrNoticeService::class)->acknowledge($notice, $staff);
        $this->assertNotNull(HrAcknowledgment::query()->where('hr_notice_id', $notice->id)->value('acknowledged_at'));

        $staff->employee->update(['team_id' => Team::query()->create(['name' => 'Moved Team', 'status' => true])->id]);
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $notice->id, 'employee_id' => $staff->employee->id]);
    }

    public function test_unauthorized_users_cannot_issue_or_publish_and_resources_do_not_offer_edit_or_delete(): void
    {
        [$owner, $staff] = $this->people();
        $category = WarningCategory::query()->create(['name' => 'Other', 'status' => true, 'created_by_user_id' => $owner->id]);

        try {
            app(EmployeeWarningService::class)->issue([
                'employee_id' => $owner->employee->id, 'warning_level' => 'final', 'warning_category_id' => $category->id,
                'title' => 'Unauthorized', 'description' => 'Must not be issued.', 'issued_date' => '2026-08-21', 'acknowledgment_required' => true,
            ], $staff);
            $this->fail('Staff issued a Warning without permission.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        try {
            app(HrNoticeService::class)->publish([
                'audience_type' => 'all', 'team_id' => null, 'employee_ids' => [], 'title' => 'Unauthorized',
                'content' => 'Must not be published.', 'priority' => 'normal', 'published_at' => now(),
                'expires_at' => null, 'acknowledgment_required' => false,
            ], $staff);
            $this->fail('Staff published a Notice without permission.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('employee_warnings', 0);
        $this->assertDatabaseCount('hr_notices', 0);
        $this->actingAs($staff)->get('/admin/employee-warnings/create')->assertForbidden();
        $this->actingAs($staff)->get('/admin/hr-notices/create')->assertForbidden();
    }

    public function test_admin_can_issue_and_all_employee_notice_materializes_every_active_login_recipient(): void
    {
        [$owner, $staff, , $other, $team] = $this->people();
        $admin = $this->user(EmployeeRole::Admin, $team);
        $category = WarningCategory::query()->create(['name' => 'Policy Violation', 'status' => true, 'created_by_user_id' => $owner->id]);

        app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id, 'warning_level' => 'final', 'warning_category_id' => $category->id,
            'title' => 'Admin-issued warning', 'description' => 'An authorized Admin warning.', 'issued_date' => '2026-08-21',
            'acknowledgment_required' => true,
        ], $admin);
        $this->assertDatabaseCount('employee_warnings', 1);

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'all', 'team_id' => null, 'employee_ids' => [],
            'title' => 'Company notice', 'content' => 'A company-wide operational notice.', 'priority' => 'normal',
            'published_at' => '2026-08-21 12:00:00', 'expires_at' => null, 'acknowledgment_required' => false,
        ], $admin);

        $this->assertSame(Employee::query()->where('status', true)->whereNotNull('user_id')->count(), $notice->recipients()->count());
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $notice->id, 'employee_id' => $other->employee->id]);
        $this->actingAs($owner)->get('/admin/employee-warnings/create')->assertOk();
        $this->actingAs($owner)->get('/admin/hr-notices/create')->assertOk();
    }

    /** @return array{User, User, User, User, Team} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Sales', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team),
            $this->user(EmployeeRole::Staff, $team),
            $this->user(EmployeeRole::Manager, $team),
            $this->user(EmployeeRole::Staff, $otherTeam),
            $team,
        ];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team->id, 'status' => true]);

        return $user->refresh();
    }
}
