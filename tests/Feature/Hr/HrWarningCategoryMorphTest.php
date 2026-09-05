<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Filament\Resources\WarningCategories\Pages\CreateWarningCategory;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\Team;
use App\Models\User;
use App\Models\WarningCategory;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HrWarningCategoryMorphTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_category_create_and_create_another_write_safe_activity_logs(): void
    {
        [$owner] = $this->people();

        Livewire::actingAs($owner)->test(CreateWarningCategory::class)
            ->fillForm(['name' => 'Attendance & Punctuality', 'status' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::actingAs($owner)->test(CreateWarningCategory::class)
            ->fillForm(['name' => 'Conduct', 'status' => true])
            ->call('createAnother')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('warning_categories', 2);
        $this->assertDatabaseHas('warning_categories', ['normalized_name' => 'attendance & punctuality', 'status' => true]);
        $this->assertDatabaseHas('warning_categories', ['normalized_name' => 'conduct', 'status' => true]);
        $this->assertDatabaseCount('activity_logs', 2);
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning_category.created', 'subject_type' => 'warning_category']);
    }

    public function test_all_hr_d2_activity_subjects_have_stable_morph_aliases(): void
    {
        [$owner, $staff, $team] = $this->people();
        $category = WarningCategory::query()->create(['name' => 'Other', 'status' => true, 'created_by_user_id' => $owner->id]);
        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id,
            'warning_level' => 'written',
            'warning_category_id' => $category->id,
            'title' => 'Recorded warning',
            'description' => 'A focused activity subject test.',
            'issued_date' => '2026-08-21',
            'acknowledgment_required' => true,
        ], $owner);
        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'team',
            'team_id' => $team->id,
            'employee_ids' => [],
            'title' => 'Recorded notice',
            'content' => 'A focused notice activity subject test.',
            'priority' => 'normal',
            'published_at' => '2026-08-21 12:00:00',
            'expires_at' => null,
            'acknowledgment_required' => true,
        ], $owner);
        app(EmployeeWarningService::class)->acknowledge($warning, $staff);
        app(EmployeeWarningService::class)->close($warning, $owner);
        app(HrNoticeService::class)->acknowledge($notice, $staff);
        app(HrNoticeService::class)->archive($notice, $owner);

        $this->assertSame('warning_category', $category->getMorphClass());
        $this->assertSame('employee_warning', $warning->getMorphClass());
        $this->assertSame('hr_notice', $notice->getMorphClass());
        $this->assertSame(WarningCategory::class, Relation::getMorphedModel('warning_category'));
        $this->assertSame(EmployeeWarning::class, Relation::getMorphedModel('employee_warning'));
        $this->assertSame(HrNotice::class, Relation::getMorphedModel('hr_notice'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning.issued', 'subject_type' => 'employee_warning']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning.acknowledged', 'subject_type' => 'employee_warning']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'warning.closed', 'subject_type' => 'employee_warning']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice.published', 'subject_type' => 'hr_notice']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice.acknowledged', 'subject_type' => 'hr_notice']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice.archived', 'subject_type' => 'hr_notice']);
    }

    public function test_morph_map_enforcement_remains_enabled(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());
        $this->expectException(ClassMorphViolationException::class);

        (new class extends Model {})->getMorphClass();
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
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team->id, 'status' => true]);

        return $user->refresh();
    }
}
