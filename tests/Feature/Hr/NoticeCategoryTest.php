<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;
use App\Filament\Resources\NoticeCategories\Pages\CreateNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\EditNoticeCategory;
use App\Models\Employee;
use App\Models\NoticeCategory;
use App\Models\Team;
use App\Models\User;
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

        Livewire::actingAs($owner)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => '  HR   Policy  ', 'status' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = NoticeCategory::query()->firstOrFail();
        $this->assertSame('HR Policy', $category->name);
        $this->assertSame('hr policy', $category->normalized_name);
        $this->assertTrue($category->status);
        $this->assertSame('notice_category', $category->getMorphClass());
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice_category.created', 'subject_type' => 'notice_category', 'subject_id' => $category->id]);

        Livewire::actingAs($owner)->test(CreateNoticeCategory::class)
            ->fillForm(['name' => 'hr policy', 'status' => true])
            ->call('create')
            ->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('notice_categories', 1);

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
}
