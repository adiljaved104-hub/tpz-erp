<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Filament\Resources\HrNotices\Pages\ViewHrNotice;
use App\Models\Employee;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\HrNoticeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class HrNoticeRecipientStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_recipient_sees_and_updates_only_their_personal_notice_state(): void
    {
        [$admin, $recipient] = $this->people();
        $notice = $this->notice($admin, $recipient);

        $component = Livewire::actingAs($recipient)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Read State')
            ->assertSee('Read')
            ->assertSee('Your Acknowledgment')
            ->assertSee('Pending')
            ->assertActionVisible('acknowledge');

        $acknowledgment = $this->acknowledgment($notice, $recipient);
        $this->assertNotNull($acknowledgment->read_at);
        $this->assertNull($acknowledgment->acknowledged_at);

        $component->callAction('acknowledge')->assertHasNoActionErrors();
        $acknowledgment->refresh();
        $this->assertNotNull($acknowledgment->acknowledged_at);
        $this->assertNotNull($acknowledgment->acknowledged_by_user_id);

        Livewire::actingAs($recipient)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Acknowledged')
            ->assertSee('Acknowledged At')
            ->assertActionHidden('acknowledge');

        try {
            app(HrNoticeService::class)->acknowledge($notice->refresh(), $recipient);
            $this->fail('An acknowledged recipient acknowledged the same Notice twice.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('hr_notice_recipients', 1);
        $this->assertDatabaseCount('hr_acknowledgments', 1);
    }

    public function test_non_recipient_admin_sees_aggregate_progress_without_personal_state_or_acknowledgment_eligibility(): void
    {
        [$admin, $recipient] = $this->people();
        $notice = $this->notice($admin, $recipient);
        $before = $this->acknowledgment($notice, $recipient)->getAttributes();

        Livewire::actingAs($admin)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Recipients')
            ->assertSee('Read')
            ->assertSee('0 / 1')
            ->assertSee('Acknowledged')
            ->assertDontSee('Read State')
            ->assertDontSee('Your Acknowledgment')
            ->assertActionHidden('acknowledge')
            ->assertActionVisible('archive');

        $this->assertSame($before, $this->acknowledgment($notice, $recipient)->getAttributes());
        $this->assertDatabaseMissing('hr_notice_recipients', [
            'hr_notice_id' => $notice->id, 'employee_id' => $admin->employee->id,
        ]);
        $this->assertDatabaseMissing('hr_acknowledgments', [
            'hr_notice_id' => $notice->id, 'employee_id' => $admin->employee->id,
        ]);

        try {
            app(HrNoticeService::class)->acknowledge($notice, $admin);
            $this->fail('A non-recipient admin acknowledged a Notice.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        app(HrNoticeService::class)->markRead($notice, $recipient);
        app(HrNoticeService::class)->acknowledge($notice, $recipient);
        Livewire::actingAs($admin)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Read')
            ->assertSee('Acknowledged')
            ->assertSee('1 / 1')
            ->assertDontSee('Read State')
            ->assertDontSee('Your Acknowledgment')
            ->assertActionHidden('acknowledge')
            ->assertActionVisible('archive');

        $this->assertDatabaseCount('hr_notice_recipients', 1);
        $this->assertDatabaseCount('hr_acknowledgments', 1);
    }

    /** @return array{User, User} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Admin, $team, 'Adil'),
            $this->user(EmployeeRole::Staff, $team, 'Asif'),
        ];
    }

    private function user(EmployeeRole $role, Team $team, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name, 'email' => $user->email, 'team_id' => $team->id, 'status' => true,
        ]);

        return $user->refresh();
    }

    private function notice(User $admin, User $recipient): HrNotice
    {
        return app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'team_id' => null,
            'employee_ids' => [$recipient->employee->id],
            'title' => 'Selected Employee Notice',
            'content' => 'Recipient-state regression coverage.',
            'priority' => 'important',
            'published_at' => '2026-08-22 10:00:00',
            'expires_at' => null,
            'acknowledgment_required' => true,
        ], $admin);
    }

    private function acknowledgment(HrNotice $notice, User $recipient): HrAcknowledgment
    {
        return HrAcknowledgment::query()
            ->where('hr_notice_id', $notice->id)
            ->where('employee_id', $recipient->employee->id)
            ->firstOrFail();
    }
}
