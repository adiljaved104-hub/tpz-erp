<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;
use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use App\Filament\Resources\NoticeCategories\Pages\CreateNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\EditNoticeCategory;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\NoticeCategory;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\HrNoticeService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class NoticeCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_category_management_normalizes_names_audits_changes_and_blocks_duplicates(): void
    {
        [$owner, $staff, $team] = $this->people();
        $manager = $this->user(EmployeeRole::Manager, $team);
        $existingCategoryCount = NoticeCategory::query()->count();

        Livewire::actingAs($owner)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => '  HR   Policy  ', 'status' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = NoticeCategory::query()->where('normalized_name', 'hr policy')->firstOrFail();
        $this->assertSame('HR Policy', $category->name);
        $this->assertSame('hr policy', $category->normalized_name);
        $this->assertTrue($category->status);
        $this->assertSame('notice_category', $category->getMorphClass());
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice_category.created', 'subject_type' => 'notice_category', 'subject_id' => $category->id]);

        Livewire::actingAs($owner)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'hr policy', 'status' => true])
            ->call('create')
            ->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('notice_categories', $existingCategoryCount + 1);

        Livewire::actingAs($owner)->test(EditNoticeCategory::class, ['record' => $category->id])
            ->fillForm(['name' => 'HR Policies', 'status' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertDatabaseHas('notice_categories', ['id' => $category->id, 'normalized_name' => 'hr policies', 'status' => false]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice_category.updated', 'subject_type' => 'notice_category']);

        Livewire::actingAs($staff)->test(CreateNoticeCategory::class)->assertForbidden();
        Livewire::actingAs($manager)->test(CreateNoticeCategory::class)->assertForbidden();
        $this->actingAs($staff)->get('/admin/notice-categories')->assertForbidden();
    }

    public function test_notice_category_is_optional_active_only_for_publication_and_historical_relation_is_preserved(): void
    {
        [$owner, $staff, $team] = $this->people();
        $active = NoticeCategory::query()->create(['name' => 'Operations', 'status' => true, 'created_by_user_id' => $owner->id]);
        $inactive = NoticeCategory::query()->create(['name' => 'Old Policy', 'status' => false, 'created_by_user_id' => $owner->id]);

        Livewire::actingAs($owner)->test(CreateHrNotice::class)
            ->assertFormFieldExists('notice_category_id', function (Select $field) use ($active, $inactive): bool {
                $options = $field->getOptions();

                return array_key_exists($active->id, $options) && ! array_key_exists($inactive->id, $options);
            });

        $service = app(HrNoticeService::class);
        $uncategorized = $service->publish($this->noticeData($team->id, null, 'Uncategorized Notice'), $owner);
        $categorized = $service->publish($this->noticeData($team->id, $active->id, 'Operations Notice'), $owner);
        $this->assertNull($uncategorized->notice_category_id);
        $this->assertSame($active->id, $categorized->notice_category_id);
        $this->assertSame('Operations', $categorized->category->name);

        $active->update(['status' => false]);
        $this->assertSame('Operations', $categorized->refresh()->category->name);
        $nextValue = (int) \DB::table('reference_sequences')->where('key', 'hr_notice:2026')->value('next_value');
        try {
            $service->publish($this->noticeData($team->id, $active->id, 'Invalid Inactive Category'), $owner);
            $this->fail('A new Notice used an inactive Category.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($nextValue, (int) \DB::table('reference_sequences')->where('key', 'hr_notice:2026')->value('next_value'));
        $this->assertDatabaseCount('hr_notices', 2);

        $this->expectException(LogicException::class);
        $active->delete();
    }

    public function test_notice_category_management_is_isolated_by_communication_scope(): void
    {
        [$owner, , $team] = $this->people();
        $officialManager = $this->user(EmployeeRole::Manager, $team);
        $announcementManager = $this->user(EmployeeRole::Manager, $team);
        $bothManager = $this->user(EmployeeRole::Manager, $team);
        $this->grantManage($officialManager, $owner, HrPermission::NoticeManage);
        $this->grantManage($announcementManager, $owner, HrPermission::AnnouncementManage);
        $this->grantManage($bothManager, $owner, HrPermission::NoticeManage);
        $this->grantManage($bothManager, $owner, HrPermission::AnnouncementManage);

        $official = $this->category($owner, 'Official Scope', 'official_notice');
        $announcement = $this->category($owner, 'Announcement Scope', 'announcement');
        $both = $this->category($owner, 'Shared Scope', 'both');
        $authorization = app(HrRecordAuthorization::class);

        $this->assertTrue($authorization->canEditNoticeCategory($officialManager, $official));
        $this->assertFalse($authorization->canEditNoticeCategory($officialManager, $announcement));
        $this->assertFalse($authorization->canEditNoticeCategory($officialManager, $both));
        $this->assertTrue($authorization->canEditNoticeCategory($announcementManager, $announcement));
        $this->assertFalse($authorization->canEditNoticeCategory($announcementManager, $official));
        $this->assertFalse($authorization->canEditNoticeCategory($announcementManager, $both));
        $this->assertTrue($authorization->canEditNoticeCategory($bothManager, $both));

        $this->actingAs($officialManager);
        $officialVisibleIds = NoticeCategoryResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($official->id, $officialVisibleIds);
        $this->assertContains($both->id, $officialVisibleIds);
        $this->assertNotContains($announcement->id, $officialVisibleIds);
        $foreignEditResponse = $this->get(NoticeCategoryResource::getUrl('edit', ['record' => $announcement->id]));
        $this->assertContains($foreignEditResponse->getStatusCode(), [403, 404]);
        $sharedEditResponse = $this->get(NoticeCategoryResource::getUrl('edit', ['record' => $both->id]));
        $this->assertContains($sharedEditResponse->getStatusCode(), [403, 404]);

        $this->actingAs($announcementManager);
        $announcementVisibleIds = NoticeCategoryResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($announcement->id, $announcementVisibleIds);
        $this->assertContains($both->id, $announcementVisibleIds);
        $this->assertNotContains($official->id, $announcementVisibleIds);
        $foreignEditResponse = $this->get(NoticeCategoryResource::getUrl('edit', ['record' => $official->id]));
        $this->assertContains($foreignEditResponse->getStatusCode(), [403, 404]);
        $sharedEditResponse = $this->get(NoticeCategoryResource::getUrl('edit', ['record' => $both->id]));
        $this->assertContains($sharedEditResponse->getStatusCode(), [403, 404]);
    }

    public function test_notice_category_create_and_edit_scope_are_checked_server_side(): void
    {
        [$owner, , $team] = $this->people();
        $officialManager = $this->user(EmployeeRole::Manager, $team);
        $announcementManager = $this->user(EmployeeRole::Manager, $team);
        $bothManager = $this->user(EmployeeRole::Manager, $team);
        $admin = $this->user(EmployeeRole::Admin, $team);
        $this->grantManage($officialManager, $owner, HrPermission::NoticeManage);
        $this->grantManage($announcementManager, $owner, HrPermission::AnnouncementManage);
        $this->grantManage($bothManager, $owner, HrPermission::NoticeManage);
        $this->grantManage($bothManager, $owner, HrPermission::AnnouncementManage);

        Livewire::actingAs($officialManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Official Manager Category', 'communication_scope' => 'official_notice', 'status' => true])
            ->call('create')->assertHasNoFormErrors();
        $officialManagerCategory = NoticeCategory::query()->where('name', 'Official Manager Category')->firstOrFail();
        Livewire::actingAs($officialManager)->test(EditNoticeCategory::class, ['record' => $officialManagerCategory->id])
            ->fillForm(['name' => 'Official Manager Category Updated', 'communication_scope' => 'official_notice', 'status' => true])
            ->call('save')->assertHasNoFormErrors();

        Livewire::actingAs($announcementManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Announcement Manager Category', 'communication_scope' => 'announcement', 'status' => true])
            ->call('create')->assertHasNoFormErrors();
        $announcementManagerCategory = NoticeCategory::query()->where('name', 'Announcement Manager Category')->firstOrFail();
        Livewire::actingAs($announcementManager)->test(EditNoticeCategory::class, ['record' => $announcementManagerCategory->id])
            ->fillForm(['name' => 'Announcement Manager Category Updated', 'communication_scope' => 'announcement', 'status' => true])
            ->call('save')->assertHasNoFormErrors();

        Livewire::actingAs($officialManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Forged Announcement Scope', 'communication_scope' => 'announcement', 'status' => true])
            ->assertSet('data.communication_scope', 'announcement')
            ->call('create');
        $this->assertDatabaseMissing('notice_categories', ['name' => 'Forged Announcement Scope']);
        Livewire::actingAs($announcementManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Forged Official Scope', 'communication_scope' => 'official_notice', 'status' => true])
            ->assertSet('data.communication_scope', 'official_notice')
            ->call('create');
        $this->assertDatabaseMissing('notice_categories', ['name' => 'Forged Official Scope']);
        Livewire::actingAs($officialManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Forged Shared Scope', 'communication_scope' => 'both', 'status' => true])
            ->assertSet('data.communication_scope', 'both')
            ->call('create');
        $this->assertDatabaseMissing('notice_categories', ['name' => 'Forged Shared Scope']);
        Livewire::actingAs($announcementManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Forged Shared Scope 2', 'communication_scope' => 'both', 'status' => true])
            ->assertSet('data.communication_scope', 'both')
            ->call('create');
        $this->assertDatabaseMissing('notice_categories', ['name' => 'Forged Shared Scope 2']);

        $official = $this->category($owner, 'Editable Official', 'official_notice');
        Livewire::actingAs($officialManager)->test(EditNoticeCategory::class, ['record' => $official->id])
            ->fillForm(['name' => 'Edited Scope', 'communication_scope' => 'both', 'status' => true])
            ->assertSet('data.communication_scope', 'both')
            ->call('save');
        $this->assertSame('official_notice', $official->fresh()->communication_scope);
        $this->assertDatabaseMissing('notice_categories', ['name' => 'Forged Shared Scope']);

        Livewire::actingAs($bothManager)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'Both-Permission Category', 'communication_scope' => 'both', 'status' => true])
            ->call('create')->assertHasNoFormErrors();
        $shared = NoticeCategory::query()->where('name', 'Both-Permission Category')->firstOrFail();
        Livewire::actingAs($bothManager)->test(EditNoticeCategory::class, ['record' => $shared->id])
            ->fillForm(['name' => 'Both-Permission Category Updated', 'communication_scope' => 'both', 'status' => false])
            ->call('save')->assertHasNoFormErrors();

        foreach ([$owner, $admin] as $broadUser) {
            $broadCategory = $this->category($owner, 'Broad Scope '.strtolower($broadUser->employee->role->value), 'both');
            $this->assertTrue(app(HrRecordAuthorization::class)->canEditNoticeCategory($broadUser, $broadCategory));
            Livewire::actingAs($broadUser)->test(EditNoticeCategory::class, ['record' => $broadCategory->id])
                ->fillForm(['name' => $broadCategory->name.' Updated', 'communication_scope' => 'both', 'status' => true])
                ->call('save')->assertHasNoFormErrors();
        }
    }

    /** @return array{User, User, Team} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations Team', 'status' => true]);

        return [$this->user(EmployeeRole::Owner, $team), $this->user(EmployeeRole::Staff, $team), $team];
    }

    /** @return array<string, mixed> */
    private function noticeData(int $teamId, ?int $categoryId, string $title): array
    {
        return [
            'audience_type' => NoticeAudienceType::Team,
            'notice_category_id' => $categoryId,
            'team_id' => $teamId,
            'employee_ids' => [],
            'title' => $title,
            'content' => 'Focused Notice Category workflow test.',
            'priority' => 'normal',
            'published_at' => '2026-08-22 10:00:00',
            'expires_at' => null,
            'acknowledgment_required' => false,
        ];
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team->id, 'status' => true]);

        return $user->refresh();
    }

    private function grantManage(User $user, User $owner, HrPermission $permission): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $user->employee->id,
            'permission_key' => $permission->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Notice Category scope authorization regression.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function category(User $owner, string $name, string $scope): NoticeCategory
    {
        return NoticeCategory::query()->create([
            'name' => $name,
            'communication_scope' => $scope,
            'status' => true,
            'created_by_user_id' => $owner->id,
        ]);
    }
}
