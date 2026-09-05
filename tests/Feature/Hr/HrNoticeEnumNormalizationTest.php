<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\HrNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrNoticeEnumNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_team_and_selected_notices_accept_enum_or_scalar_audiences_atomically(): void
    {
        $teamA = Team::query()->create(['name' => 'Operations', 'status' => true]);
        $teamB = Team::query()->create(['name' => 'Sales', 'status' => true]);
        $owner = $this->user(EmployeeRole::Owner, $teamA);
        $staffA = $this->user(EmployeeRole::Staff, $teamA);
        $staffB = $this->user(EmployeeRole::Staff, $teamB);
        $service = app(HrNoticeService::class);

        try {
            $service->publish($this->noticeData(NoticeAudienceType::Team, ['team_id' => null, 'title' => 'Invalid team notice']), $owner);
            $this->fail('A Team Notice without a Team was published.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('hr_notices', 0);
        $this->assertDatabaseCount('hr_notice_recipients', 0);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'hr_notice:2026', 'next_value' => 1]);

        Livewire::actingAs($owner)->test(CreateHrNotice::class)
            ->fillForm($this->noticeData(NoticeAudienceType::All, ['title' => 'All Employees Notice']))
            ->call('create')
            ->assertHasNoFormErrors();
        $allNoticeId = (int) \DB::table('hr_notices')->where('reference', 'NTC-2026-000001')->value('id');
        $this->assertSame(3, \DB::table('hr_notice_recipients')->where('hr_notice_id', $allNoticeId)->count());

        $teamNotice = $service->publish($this->noticeData(NoticeAudienceType::Team, [
            'team_id' => $teamA->id,
            'title' => 'Team Notice',
        ]), $owner);
        $this->assertSame('NTC-2026-000002', $teamNotice->reference);
        $this->assertEqualsCanonicalizing(
            [$owner->employee->id, $staffA->employee->id],
            $teamNotice->recipients()->pluck('employee_id')->all(),
        );

        $selectedNotice = $service->publish($this->noticeData(NoticeAudienceType::Selected->value, [
            'employee_ids' => [$staffB->employee->id],
            'title' => 'Selected Employees Notice',
        ]), $owner);
        $this->assertSame('NTC-2026-000003', $selectedNotice->reference);
        $this->assertSame([$staffB->employee->id], $selectedNotice->recipients()->pluck('employee_id')->all());

        $this->assertDatabaseCount('hr_notices', 3);
        $this->assertDatabaseCount('hr_notice_recipients', 6);
        $this->assertDatabaseCount('notifications', 6);
        $this->assertDatabaseCount('activity_logs', 3);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice.published', 'subject_type' => 'hr_notice']);
        $this->assertDatabaseHas('reference_sequences', ['key' => 'hr_notice:2026', 'next_value' => 4]);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function noticeData(NoticeAudienceType|string $audience, array $overrides = []): array
    {
        return array_replace([
            'audience_type' => $audience,
            'team_id' => null,
            'employee_ids' => [],
            'title' => 'HR Notice',
            'content' => 'Focused Notice enum normalization test.',
            'priority' => 'normal',
            'published_at' => '2026-08-21 12:00:00',
            'expires_at' => null,
            'acknowledgment_required' => true,
        ], $overrides);
    }

    private function user(EmployeeRole $role, Team $team): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'team_id' => $team->id, 'status' => true]);

        return $user->refresh();
    }
}
