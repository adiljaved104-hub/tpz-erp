<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrCommunicationType;
use App\Enums\HrPermission;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Resources\EmployeeWarnings\Pages\CreateEmployeeWarning;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Filament\Resources\HrNotices\Pages\ViewHrNotice;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\EmployeeWarning;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\Team;
use App\Models\User;
use App\Models\WarningCategory;
use App\Models\WarningTemplate;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Mobile\NotificationTarget;
use App\Services\Notifications\HrRecordNotificationDispatcher;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_notice_records_default_to_official_notice_and_seeded_templates_keep_warnings_separate(): void
    {
        [$owner] = $this->people();
        $notice = HrNotice::query()->create([
            'reference' => 'NTC-2026-900001', 'title' => 'Legacy-Compatible Notice', 'content' => 'Existing notices remain official.',
            'audience_type' => 'selected', 'priority' => 'normal', 'published_at' => now(), 'acknowledgment_required' => false,
            'published_by_user_id' => $owner->id,
        ]);

        $this->assertSame('official_notice', $notice->fresh()->communication_type);
        $this->assertSame(18, NoticeCategory::query()->where('status', true)->count());
        $this->assertDatabaseHas('notice_templates', [
            'name' => 'Stock Adjustment Control Reminder', 'communication_type' => 'official_notice',
            'default_priority' => 'high', 'default_acknowledgment_required' => 1,
        ]);
        $this->assertDatabaseHas('notice_templates', [
            'name' => 'Employee Appreciation', 'communication_type' => 'announcement',
            'default_priority' => 'normal', 'default_acknowledgment_required' => 0,
        ]);
        $this->assertDatabaseHas('warning_templates', ['name' => 'Late Arrival Warning', 'default_warning_level' => 'verbal']);
        $this->assertDatabaseHas('warning_templates', ['name' => 'Repeated Late Arrival', 'default_warning_level' => 'written']);
        $this->assertDatabaseHas('warning_templates', ['name' => 'Unauthorized Absence', 'default_warning_level' => 'written']);
        $this->assertSame(0, DB::table('employee_warnings')->count());
        $this->assertSame(0, DB::table('hr_notice_recipients')->where('hr_notice_id', $notice->id)->count());
    }

    public function test_announcement_publishes_to_materialized_recipients_with_read_ack_inbox_and_mobile_support(): void
    {
        [$owner, $staff, , $outsider] = $this->people();
        $announcement = app(HrNoticeService::class)->publishAnnouncement($this->data([$staff->employee->id], [
            'title' => 'Office Maintenance', 'content' => 'Network work begins after 6 PM.', 'priority' => 'high',
            'acknowledgment_required' => false, 'expires_at' => now()->addDay()->toDateTimeString(),
        ]), $owner);

        $this->assertSame('announcement', $announcement->communication_type);
        $this->assertFalse($announcement->acknowledgment_required);
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $announcement->id, 'employee_id' => $staff->employee->id]);
        $this->assertDatabaseMissing('hr_notice_recipients', ['hr_notice_id' => $announcement->id, 'employee_id' => $outsider->employee->id]);
        $this->assertDatabaseHas('hr_acknowledgments', ['hr_notice_id' => $announcement->id, 'employee_id' => $staff->employee->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $staff->id, 'type' => 'notice.published']);
        $notification = $staff->notifications()->where('type', 'notice.published')->firstOrFail();
        $this->assertSame('announcement', $notification->data['communication_type']);
        $this->assertSame(1, $staff->notifications()->where('type', 'notice.published')->count());

        app(HrRecordNotificationDispatcher::class)->communicationPublished($announcement);
        $this->assertSame(1, $staff->notifications()->where('type', 'notice.published')->count(), 'Retried delivery must deduplicate.');
        $opened = app(NotificationInboxService::class)->open($staff, (string) $notification->id);
        $this->assertTrue($opened['available']);
        $this->assertStringContainsString('/admin/announcements/', $opened['url']);
        $this->assertSame(['module' => 'hr/notices', 'id' => $announcement->id], app(NotificationTarget::class)->resolve($staff, $notification->data));
        $this->assertNull(app(NotificationTarget::class)->resolve($outsider, $notification->data));
        $this->assertTrue(app(HrRecordAuthorization::class)->canViewNotice($staff, $announcement));
        $this->assertFalse(app(HrRecordAuthorization::class)->canViewNotice($outsider, $announcement));
        $this->assertDatabaseCount('employee_warnings', 0);

        app(HrNoticeService::class)->markRead($announcement, $staff);
        $this->assertNotNull(HrAcknowledgment::query()->where('hr_notice_id', $announcement->id)->value('read_at'));
        $this->assertNull(HrAcknowledgment::query()->where('hr_notice_id', $announcement->id)->value('acknowledged_at'));

        $mobile = $this->withToken($staff->createToken('Announcement Test')->plainTextToken);
        $list = $mobile->getJson('/api/mobile/v1/workspace/hr/notices');
        $list->assertOk();
        $this->assertSame('announcement', $list->json('data.0.communication_type'));
        $this->assertSame('read', $list->json('data.0.read_state'));
        $mobile->getJson('/api/mobile/v1/workspace/hr/notices/'.$announcement->id)->assertOk()->assertJsonPath('data.fields.communication_type', 'announcement');
    }

    public function test_announcement_permissions_are_independent_from_official_notice_permissions(): void
    {
        [$owner, $staff, , $outsider, $team] = $this->people();
        $announcement = app(HrNoticeService::class)->publishAnnouncement($this->data([$staff->employee->id]), $owner);
        $official = app(HrNoticeService::class)->publish($this->data([$staff->employee->id], ['title' => 'Official policy']), $owner);

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => HrPermission::AnnouncementView->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Independent announcement visibility regression.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertFalse(app(HrRecordAuthorization::class)->canViewNotice($staff, $announcement));
        $this->assertTrue(app(HrRecordAuthorization::class)->canViewNotice($staff, $official));
        $this->actingAs($staff);
        $this->assertTrue(HrNoticeResource::canViewAny());
        $this->assertTrue(HrNoticeResource::canView($official));
        Livewire::actingAs($staff)->test(ViewAnnouncement::class, ['record' => $announcement->id])->assertForbidden();
        Livewire::actingAs($staff)->test(ViewHrNotice::class, ['record' => $official->id])->assertSuccessful();
        Livewire::actingAs($outsider)->test(ViewAnnouncement::class, ['record' => $announcement->id])->assertForbidden();
        Livewire::actingAs($owner)->test(ViewAnnouncement::class, ['record' => $announcement->id])->assertSuccessful();
        $this->assertNull(HrNoticeResource::getRecordRouteBindingEloquentQuery()->find($announcement->id));

        $group = app(EmployeePermissionCatalog::class)->groups()['Attendance & Leave'];
        $keys = collect($group)->pluck('key')->all();
        foreach ([HrPermission::AnnouncementView, HrPermission::AnnouncementPublish, HrPermission::AnnouncementManage] as $permission) {
            $this->assertContains($permission->value, $keys);
        }
        $technician = $this->user(EmployeeRole::Technician, $team);
        foreach ([HrPermission::AnnouncementView, HrPermission::AnnouncementPublish, HrPermission::AnnouncementManage] as $permission) {
            $this->assertFalse(app(HrRecordAuthorization::class)->allows($technician, $permission));
        }
        $module = collect(app(AccessControlModuleRegistry::class)->modules())->firstWhere('key', 'announcements');
        $this->assertNotNull($module);
        $this->assertSame([HrPermission::AnnouncementView->value], $module['view_keys']);
        $this->assertSame([HrPermission::AnnouncementPublish->value, HrPermission::AnnouncementManage->value], $module['edit_keys']);
    }

    public function test_manager_announcement_publishing_remains_team_scoped_and_separate_from_official_notice_publishing(): void
    {
        [$owner, $staff, $manager, $outsider, $team] = $this->people();
        $managerEmployeeId = $manager->employee->id;
        EmployeePermissionOverride::query()->create([
            'employee_id' => $managerEmployeeId,
            'permission_key' => HrPermission::AnnouncementPublish->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Team-scoped announcement publishing test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($managerEmployeeId);

        $authorization = app(HrRecordAuthorization::class);
        $this->assertTrue($authorization->allows($manager, HrPermission::AnnouncementPublish));
        $this->assertFalse($authorization->allows($manager, HrPermission::NoticePublish));
        $this->assertFalse($authorization->canPublishCommunication($manager, HrCommunicationType::Announcement, NoticeAudienceType::All, null, []));
        $this->assertFalse($authorization->canPublishCommunication($manager, HrCommunicationType::Announcement, NoticeAudienceType::Selected, null, [$outsider->employee->id]));

        $announcement = app(HrNoticeService::class)->publishAnnouncement($this->data([$staff->employee->id]), $manager);

        $this->assertSame('announcement', $announcement->communication_type);
        $this->assertSame($team->id, $manager->employee->team_id);
        $this->assertDatabaseHas('hr_notice_recipients', ['hr_notice_id' => $announcement->id, 'employee_id' => $staff->employee->id]);
        $this->assertDatabaseMissing('hr_notice_recipients', ['hr_notice_id' => $announcement->id, 'employee_id' => $outsider->employee->id]);
    }

    public function test_mobile_communication_publication_rejects_unassigned_cross_team_and_mixed_selected_recipients_without_side_effects(): void
    {
        [$owner, $staff, $manager, $outsider] = $this->people();
        $unassigned = $this->userWithoutTeam();
        $this->grantCommunicationPublishing($manager, $owner);

        $token = $manager->createToken('Cross-team audience regression')->plainTextToken;
        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/notices', $this->data([$unassigned->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();

        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/notices', $this->data([$staff->employee->id, $unassigned->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();

        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/notices', $this->data([$outsider->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();

        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/announcements', $this->data([$unassigned->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();

        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/announcements', $this->data([$staff->employee->id, $outsider->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();

        $this->withToken($token)->postJson('/api/mobile/v1/workspace/hr/announcements', $this->data([$outsider->employee->id]))->assertForbidden();
        $this->assertNoCommunicationSideEffects();
    }

    public function test_selected_communication_audiences_allow_same_team_and_preserve_owner_company_wide_access(): void
    {
        [$owner, $staff, $manager, $outsider, $team] = $this->people();
        $admin = $this->user(EmployeeRole::Admin, $team);
        $this->grantCommunicationPublishing($manager, $owner);
        $authorization = app(HrRecordAuthorization::class);

        $this->assertTrue($authorization->canPublishCommunication(
            $manager,
            HrCommunicationType::OfficialNotice,
            NoticeAudienceType::Selected,
            null,
            [$staff->employee->id],
        ));
        $this->assertTrue($authorization->canPublishCommunication(
            $manager,
            HrCommunicationType::Announcement,
            NoticeAudienceType::Selected,
            null,
            [$staff->employee->id],
        ));
        $this->assertFalse($authorization->canPublishCommunication(
            $manager,
            HrCommunicationType::OfficialNotice,
            NoticeAudienceType::Selected,
            null,
            [$staff->employee->id, $outsider->employee->id],
        ));
        $this->assertFalse($authorization->canPublishCommunication(
            $manager,
            HrCommunicationType::Announcement,
            NoticeAudienceType::Selected,
            null,
            [$staff->employee->id, $this->userWithoutTeam()->employee->id],
        ));
        $this->assertFalse($authorization->canPublishCommunication(
            $manager,
            HrCommunicationType::OfficialNotice,
            NoticeAudienceType::All,
            null,
            [],
        ));
        $this->assertTrue($authorization->canPublishCommunication(
            $owner,
            HrCommunicationType::OfficialNotice,
            NoticeAudienceType::All,
            null,
            [],
        ));
        $this->assertTrue($authorization->canPublishCommunication(
            $owner,
            HrCommunicationType::Announcement,
            NoticeAudienceType::All,
            null,
            [],
        ));
        $this->assertTrue($authorization->canPublishCommunication(
            $admin,
            HrCommunicationType::OfficialNotice,
            NoticeAudienceType::All,
            null,
            [],
        ));
        $this->assertTrue($authorization->canPublishCommunication(
            $admin,
            HrCommunicationType::Announcement,
            NoticeAudienceType::All,
            null,
            [],
        ));
        $this->assertSame($team->id, $manager->employee->team_id);
    }

    public function test_announcement_template_autofills_only_an_untouched_form_and_wrong_type_templates_are_rejected(): void
    {
        [$owner] = $this->people();
        $category = NoticeCategory::query()->create(['name' => 'System Updates', 'communication_scope' => 'announcement', 'status' => true]);
        $template = NoticeTemplate::query()->create([
            'communication_type' => 'announcement', 'notice_category_id' => $category->id, 'name' => 'Service Update',
            'default_title' => 'Service Window', 'default_content' => 'Service will pause at [Time].',
            'default_priority' => 'urgent', 'default_acknowledgment_required' => true, 'status' => true,
        ]);
        $secondTemplate = NoticeTemplate::query()->create([
            'communication_type' => 'announcement', 'notice_category_id' => $category->id, 'name' => 'Second Update',
            'default_title' => 'Second Title', 'default_content' => 'Second Content',
            'default_priority' => 'high', 'default_acknowledgment_required' => false, 'status' => true,
        ]);
        $officialOnlyCategory = NoticeCategory::query()->create(['name' => 'Official Only', 'communication_scope' => 'official_notice', 'status' => true]);

        Livewire::actingAs($owner)->test(CreateAnnouncement::class)
            ->set('data.notice_category_id', $category->id)
            ->set('data.notice_template_id', $template->id)
            ->assertSet('data.title', 'Service Window')
            ->assertSet('data.content', 'Service will pause at [Time].')
            ->assertSet('data.priority', 'urgent')
            ->assertSet('data.acknowledgment_required', true)
            ->set('data.title', 'Owner edited title')
            ->set('data.content', 'Owner edited content')
            ->set('data.notice_template_id', $secondTemplate->id)
            ->assertSet('data.title', 'Owner edited title')
            ->assertSet('data.content', 'Owner edited content')
            ->assertSet('data.priority', 'urgent')
            ->assertSet('data.acknowledgment_required', true);

        try {
            app(HrNoticeService::class)->publishAnnouncement($this->data([$owner->employee->id], [
                'notice_category_id' => $officialOnlyCategory->id,
                'notice_template_id' => $template->id,
            ]), $owner);
            $this->fail('An Announcement used a category/template outside its configured scope.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_expired_announcement_remains_auditable_but_is_not_notified_as_current(): void
    {
        [$owner, $staff] = $this->people();
        $announcement = app(HrNoticeService::class)->publishAnnouncement($this->data([$staff->employee->id], [
            'published_at' => now()->subDays(3)->toDateTimeString(),
            'expires_at' => now()->subDay()->toDateTimeString(),
        ]), $owner);

        $this->assertDatabaseHas('hr_notices', ['id' => $announcement->id, 'communication_type' => 'announcement', 'status' => 'active']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $staff->id, 'type' => 'notice.published']);
        $this->assertTrue(app(HrRecordAuthorization::class)->canViewNotice($staff, $announcement), 'Expired history stays recipient-readable.');
        $this->actingAs($staff);
        $this->assertTrue(AnnouncementResource::canViewAny());
        $this->assertTrue(AnnouncementResource::canView($announcement));
        Livewire::actingAs($staff)->test(ViewAnnouncement::class, ['record' => $announcement->id])->assertSuccessful()->assertSee('Expired');
    }

    public function test_warning_templates_remain_in_warning_history_not_announcement_history(): void
    {
        [$owner, $staff] = $this->people();
        $category = WarningCategory::query()->where('normalized_name', 'attendance & timing')->firstOrFail();
        $template = WarningTemplate::query()->where('name', 'Late Arrival Warning')->firstOrFail();

        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id, 'warning_level' => $template->default_warning_level,
            'warning_category_id' => $category->id, 'warning_template_id' => $template->id,
            'title' => 'Late Arrival — 17 Oct', 'description' => 'Attendance review details.',
            'issued_date' => now()->toDateString(), 'acknowledgment_required' => true,
        ], $owner);
        $announcement = app(HrNoticeService::class)->publishAnnouncement($this->data([$staff->employee->id]), $owner);

        $this->assertSame($template->id, $warning->template->id);
        $this->assertDatabaseHas('employee_warnings', ['id' => $warning->id, 'warning_template_id' => $template->id]);
        $this->assertDatabaseHas('hr_notices', ['id' => $announcement->id, 'communication_type' => 'announcement']);
        $this->assertDatabaseCount('employee_warnings', 1);
        $this->assertSame('announcement', $announcement->communication_type);
    }

    public function test_warning_template_acknowledgment_defaults_apply_and_warning_fields_remain_customizable(): void
    {
        [$owner, $staff] = $this->people();
        $category = WarningCategory::query()->where('status', true)->firstOrFail();
        $trueTemplate = $this->warningTemplate($category, $owner, 'Acknowledgment Required Template', true, 'verbal');
        $falseTemplate = $this->warningTemplate($category, $owner, 'No Acknowledgment Template', false, 'written');

        Livewire::actingAs($owner)->test(CreateEmployeeWarning::class)
            ->set('data.warning_category_id', $category->id)
            ->set('data.acknowledgment_required', false)
            ->set('data.warning_template_id', $trueTemplate->id)
            ->assertSet('data.acknowledgment_required', true)
            ->assertSet('data.title', $trueTemplate->default_title)
            ->assertSet('data.description', $trueTemplate->default_description)
            ->assertSet('data.warning_level', $trueTemplate->default_warning_level)
            ->set('data.title', 'Customized Warning Title')
            ->set('data.description', 'Customized disciplinary details.')
            ->set('data.warning_level', 'written')
            ->set('data.acknowledgment_required', false)
            ->assertSet('data.title', 'Customized Warning Title')
            ->assertSet('data.description', 'Customized disciplinary details.')
            ->assertSet('data.warning_level', 'written')
            ->assertSet('data.acknowledgment_required', false);

        Livewire::actingAs($owner)->test(CreateEmployeeWarning::class)
            ->set('data.employee_id', $staff->employee->id)
            ->set('data.warning_category_id', $category->id)
            ->set('data.warning_template_id', $falseTemplate->id)
            ->assertSet('data.acknowledgment_required', false)
            ->assertSet('data.title', $falseTemplate->default_title)
            ->assertSet('data.description', $falseTemplate->default_description)
            ->assertSet('data.warning_level', $falseTemplate->default_warning_level)
            ->set('data.issued_date', now()->toDateString())
            ->call('create')->assertHasNoFormErrors();

        $warning = EmployeeWarning::query()->firstOrFail();
        $this->assertSame($falseTemplate->id, $warning->warning_template_id);
        $this->assertFalse($warning->acknowledgment_required);
        $this->assertSame($falseTemplate->default_title, $warning->title);
        $this->assertSame($falseTemplate->default_description, $warning->description);
        $this->assertSame('written', $warning->warning_level->value);
        $this->assertDatabaseCount('hr_notices', 0);
        $this->assertDatabaseCount('employee_warnings', 1);
    }

    /** @param array<int, int> $employeeIds @param array<string, mixed> $overrides @return array<string, mixed> */
    private function data(array $employeeIds, array $overrides = []): array
    {
        return array_merge([
            'audience_type' => 'selected', 'team_id' => null, 'employee_ids' => $employeeIds,
            'title' => 'Company Update', 'content' => 'A general information update.', 'priority' => 'normal',
            'published_at' => now()->toDateTimeString(), 'expires_at' => null, 'acknowledgment_required' => false,
        ], $overrides);
    }

    private function warningTemplate(WarningCategory $category, User $owner, string $name, bool $acknowledgmentRequired, string $level): WarningTemplate
    {
        return WarningTemplate::query()->create([
            'warning_category_id' => $category->id,
            'name' => $name,
            'default_title' => $name.' title',
            'default_description' => $name.' details',
            'default_warning_level' => $level,
            'default_acknowledgment_required' => $acknowledgmentRequired,
            'status' => true,
            'created_by_user_id' => $owner->id,
        ]);
    }

    private function grantCommunicationPublishing(User $publisher, User $grantedBy): void
    {
        foreach ([HrPermission::NoticePublish, HrPermission::AnnouncementPublish] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $publisher->employee->id,
                'permission_key' => $permission->value,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $grantedBy->id,
                'reason' => 'Team-scoped communication audience regression.',
            ]);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($publisher->employee->id);
    }

    private function assertNoCommunicationSideEffects(): void
    {
        $this->assertDatabaseCount('hr_notices', 0);
        $this->assertDatabaseCount('hr_notice_recipients', 0);
        $this->assertDatabaseCount('hr_acknowledgments', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    private function userWithoutTeam(): User
    {
        $user = User::factory()->create(['email' => 'communications-unassigned-'.Str::uuid().'@techpointzone.com']);
        Employee::factory()->for($user)->role(EmployeeRole::Staff)->create([
            'email' => $user->email,
            'team_id' => null,
            'status' => true,
        ]);

        return $user->refresh();
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
        $user = User::factory()->create(['email' => 'communications-'.Str::uuid().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }
}
