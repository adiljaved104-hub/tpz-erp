<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Filament\Pages\Hr\Attendance;
use App\Filament\Pages\Hr\BiometricSync;
use App\Filament\Pages\Hr\CompOff;
use App\Filament\Pages\Hr\HrSettings;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Filament\Pages\Hr\Performance;
use App\Filament\Pages\Hr\PublicHolidays;
use App\Filament\Pages\Hr\WorkSchedules;
use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Filament\Resources\EmployeeWarnings\Pages\ListEmployeeWarnings;
use App\Filament\Resources\EmployeeWarnings\Pages\ViewEmployeeWarning;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Filament\Resources\HrNotices\Pages\ListHrNotices;
use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use App\Filament\Resources\NoticeTemplates\NoticeTemplateResource;
use App\Filament\Resources\WarningCategories\WarningCategoryResource;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Models\WarningCategory;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HrNavigationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_navigation_and_issue_action_follow_effective_permissions_while_urls_remain_protected(): void
    {
        [$owner, $staff, $team] = $this->people();
        $category = WarningCategory::query()->create([
            'name' => 'Conduct', 'status' => true, 'created_by_user_id' => $owner->id,
        ]);
        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id,
            'warning_level' => 'written',
            'warning_category_id' => $category->id,
            'title' => 'Focused authorization test',
            'description' => 'Private warning details.',
            'issued_date' => '2026-08-22',
            'acknowledgment_required' => false,
        ], $owner);

        $this->actingAs($staff);
        $this->assertTrue(EmployeeWarningResource::shouldRegisterNavigation());
        Livewire::test(ListEmployeeWarnings::class)->assertActionHidden('create');
        Livewire::test(ViewEmployeeWarning::class, ['record' => $warning->id])->assertActionHidden('close');

        $this->override($staff, HrPermission::WarningViewOwn, EmployeePermissionEffect::Deny, $owner);
        $this->assertFalse(EmployeeWarningResource::shouldRegisterNavigation());
        $this->get('/admin/employee-warnings')->assertForbidden();
        $this->get('/admin/employee-warnings/'.$warning->id)->assertForbidden();

        $this->override($staff, HrPermission::WarningViewOwn, EmployeePermissionEffect::Allow, $owner);
        $this->assertTrue(EmployeeWarningResource::shouldRegisterNavigation());
        $this->actingAs($staff);
        $this->get('/admin/employee-warnings')->assertOk();

        $this->actingAs($owner);
        $this->assertTrue(EmployeeWarningResource::shouldRegisterNavigation());
        $this->assertTrue(WarningCategoryResource::shouldRegisterNavigation());
        Livewire::actingAs($owner)->test(ListEmployeeWarnings::class)->assertActionVisible('create');
    }

    public function test_notice_navigation_requires_materialized_access_and_management_navigation_uses_notice_manage(): void
    {
        [$owner, $staff, $team] = $this->people();

        $this->actingAs($staff);
        $this->assertFalse(HrNoticeResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeCategoryResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeTemplateResource::shouldRegisterNavigation());
        $this->get('/admin/notice-categories')->assertForbidden();
        $this->get('/admin/notice-templates')->assertForbidden();

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'team_id' => null,
            'employee_ids' => [$staff->employee->id],
            'title' => 'Applicable employee Notice',
            'content' => 'Materialized Notice content.',
            'priority' => 'normal',
            'published_at' => '2026-08-22 10:00:00',
            'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);

        $this->actingAs($staff);
        $this->assertTrue(HrNoticeResource::shouldRegisterNavigation());
        Livewire::test(ListHrNotices::class)
            ->assertCanSeeTableRecords([$notice])
            ->assertActionHidden('create');

        $this->override($staff, HrPermission::NoticeManage, EmployeePermissionEffect::Allow, $owner);
        $this->assertTrue(NoticeCategoryResource::shouldRegisterNavigation());
        $this->assertTrue(NoticeTemplateResource::shouldRegisterNavigation());

        $this->override($staff, HrPermission::NoticeManage, EmployeePermissionEffect::Deny, $owner);
        $this->assertFalse(NoticeCategoryResource::shouldRegisterNavigation());
        $this->assertFalse(NoticeTemplateResource::shouldRegisterNavigation());

        $this->actingAs($owner);
        $this->assertTrue(HrNoticeResource::shouldRegisterNavigation());
        $this->assertTrue(NoticeCategoryResource::shouldRegisterNavigation());
        $this->assertTrue(NoticeTemplateResource::shouldRegisterNavigation());
        Livewire::test(ListHrNotices::class)->assertActionVisible('create');
    }

    public function test_other_hr_page_navigation_mirrors_existing_page_authorization(): void
    {
        [$owner, $staff] = $this->people();

        $this->actingAs($staff);
        $this->assertTrue(Attendance::shouldRegisterNavigation());
        $this->assertTrue(LeaveManagement::shouldRegisterNavigation());
        $this->assertTrue(CompOff::shouldRegisterNavigation());
        $this->assertTrue(Performance::shouldRegisterNavigation());
        $this->assertFalse(WorkSchedules::shouldRegisterNavigation());
        $this->assertFalse(PublicHolidays::shouldRegisterNavigation());
        $this->assertFalse(BiometricSync::shouldRegisterNavigation());
        $this->assertFalse(HrSettings::shouldRegisterNavigation());
        $this->get('/admin/hr/work-schedules')->assertForbidden();

        $this->actingAs($owner);
        $this->assertTrue(Attendance::shouldRegisterNavigation());
        $this->assertTrue(LeaveManagement::shouldRegisterNavigation());
        $this->assertTrue(CompOff::shouldRegisterNavigation());
        $this->assertTrue(Performance::shouldRegisterNavigation());
        $this->assertTrue(WorkSchedules::shouldRegisterNavigation());
        $this->assertTrue(PublicHolidays::shouldRegisterNavigation());
        $this->assertTrue(BiometricSync::shouldRegisterNavigation());
        $this->assertTrue(HrSettings::shouldRegisterNavigation());
    }

    /** @return array{User, User, Team} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team),
            $this->user(EmployeeRole::Staff, $team),
            $team,
        ];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create(['email' => strtolower($role->value).'-'.str()->random(8).'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function override(User $employeeUser, HrPermission $permission, EmployeePermissionEffect $effect, User $actor): void
    {
        EmployeePermissionOverride::query()->updateOrCreate(
            ['employee_id' => $employeeUser->employee->id, 'permission_key' => $permission->value],
            ['effect' => $effect, 'granted_by_user_id' => $actor->id, 'reason' => 'Focused HR navigation test.'],
        );
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employeeUser->employee->id);
    }
}
