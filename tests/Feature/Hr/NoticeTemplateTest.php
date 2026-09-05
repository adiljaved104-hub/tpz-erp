<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeeRole;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;
use App\Filament\Resources\NoticeTemplates\Pages\CreateNoticeTemplate;
use App\Filament\Resources\NoticeTemplates\Pages\EditNoticeTemplate;
use App\Models\Employee;
use App\Models\HrNotice;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\HrNoticeService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class NoticeTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_template_management_is_audited_and_direct_urls_are_protected(): void
    {
        [$owner, $staff, $manager] = $this->people();
        $category = NoticeCategory::query()->create(['name' => 'Attendance', 'status' => true, 'created_by_user_id' => $owner->id]);

        Livewire::actingAs($owner)->test(CreateNoticeTemplate::class)
            ->fillForm([
                'notice_category_id' => $category->id,
                'name' => 'Attendance Policy Reminder',
                'default_title' => 'Attendance Policy Reminder',
                'default_content' => 'Please review the current attendance policy.',
                'default_priority' => 'important',
                'default_acknowledgment_required' => true,
                'status' => true,
            ])->call('create')->assertHasNoFormErrors();

        $template = NoticeTemplate::query()->firstOrFail();
        $this->assertSame('attendance policy reminder', $template->normalized_name);
        $this->assertSame('notice_template', $template->getMorphClass());
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice_template.created', 'subject_type' => 'notice_template', 'subject_id' => $template->id]);

        Livewire::actingAs($owner)->test(EditNoticeTemplate::class, ['record' => $template->id])
            ->fillForm(['status' => false])
            ->call('save')->assertHasNoFormErrors();
        $this->assertFalse($template->refresh()->status);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notice_template.updated', 'subject_type' => 'notice_template']);

        Livewire::actingAs($staff)->test(CreateNoticeTemplate::class)->assertForbidden();
        Livewire::actingAs($manager)->test(CreateNoticeTemplate::class)->assertForbidden();
        $this->actingAs($staff)->get('/admin/notice-templates')->assertForbidden();
    }

    public function test_template_selector_is_category_scoped_reactive_and_never_auto_publishes(): void
    {
        [$owner, , , $team] = $this->people();
        $attendance = NoticeCategory::query()->create(['name' => 'Attendance', 'status' => true, 'created_by_user_id' => $owner->id]);
        $operations = NoticeCategory::query()->create(['name' => 'Operations', 'status' => true, 'created_by_user_id' => $owner->id]);
        $template = $this->template($attendance, $owner, 'Attendance Reminder', true);
        $inactive = $this->template($attendance, $owner, 'Old Reminder', false);
        $other = $this->template($operations, $owner, 'Operations Reminder', true);

        $component = Livewire::actingAs($owner)->test(CreateHrNotice::class)
            ->set('data.notice_category_id', $attendance->id)
            ->assertFormFieldExists('notice_template_id', function (Select $field) use ($template, $inactive, $other): bool {
                $options = $field->getOptions();

                return array_key_exists($template->id, $options)
                    && ! array_key_exists($inactive->id, $options)
                    && ! array_key_exists($other->id, $options);
            })
            ->set('data.notice_template_id', $template->id)
            ->assertSet('data.title', $template->default_title)
            ->assertSet('data.content', $template->default_content)
            ->assertSet('data.priority', 'important')
            ->assertSet('data.acknowledgment_required', true);

        $this->assertDatabaseCount('hr_notices', 0);

        $component->set('data.title', 'Edited Before Publication')
            ->set('data.content', 'HR deliberately edited the copied Template content.')
            ->set('data.audience_type', NoticeAudienceType::Team->value)
            ->set('data.team_id', $team->id)
            ->set('data.published_at', '2026-08-22 10:00:00')
            ->call('create')->assertHasNoFormErrors();

        $notice = HrNotice::query()->firstOrFail();
        $this->assertSame($template->id, $notice->notice_template_id);
        $this->assertSame($attendance->id, $notice->notice_category_id);
        $this->assertSame('Edited Before Publication', $notice->title);
        $this->assertSame('HR deliberately edited the copied Template content.', $notice->content);

        $template->update(['default_title' => 'Changed Template Title', 'default_content' => 'Changed later.']);
        $notice->refresh();
        $this->assertSame('Edited Before Publication', $notice->title);
        $this->assertSame('HR deliberately edited the copied Template content.', $notice->content);

        Livewire::actingAs($owner)->test(CreateHrNotice::class)
            ->set('data.notice_category_id', $attendance->id)
            ->set('data.notice_template_id', $template->id)
            ->set('data.notice_category_id', $operations->id)
            ->assertSet('data.notice_template_id', null)
            ->assertSet('data.title', 'Changed Template Title')
            ->set('data.notice_template_id', null)
            ->assertSet('data.title', 'Changed Template Title');
    }

    public function test_inactive_category_and_template_are_blocked_for_new_use_but_history_remains_manageable(): void
    {
        [$owner, , , $team] = $this->people();
        $category = NoticeCategory::query()->create(['name' => 'HR Policy', 'status' => true, 'created_by_user_id' => $owner->id]);
        $template = $this->template($category, $owner, 'Policy Reminder', true);

        $notice = app(HrNoticeService::class)->publish($this->noticeData($team, $category, $template), $owner);
        $template->update(['status' => false]);
        $category->update(['status' => false]);

        $this->assertSame('Policy Reminder', $notice->refresh()->template->name);
        Livewire::actingAs($owner)->test(EditNoticeTemplate::class, ['record' => $template->id])
            ->assertFormSet(['notice_category_id' => $category->id]);

        try {
            app(HrNoticeService::class)->publish($this->noticeData($team, $category, $template, 'Blocked Publication'), $owner);
            $this->fail('An inactive Template or Category was used for a new Notice.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('hr_notices', 1);

        try {
            NoticeTemplate::query()->create([
                'notice_category_id' => $category->id,
                'name' => 'New Template on Inactive Category',
                'default_title' => 'Invalid',
                'default_content' => 'Invalid category use.',
                'default_priority' => 'normal',
                'default_acknowledgment_required' => false,
                'status' => true,
                'created_by_user_id' => $owner->id,
            ]);
            $this->fail('A new Template used an inactive Category.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $template->delete();
    }

    private function template(NoticeCategory $category, User $owner, string $name, bool $active): NoticeTemplate
    {
        return NoticeTemplate::query()->create([
            'notice_category_id' => $category->id,
            'name' => $name,
            'default_title' => $name,
            'default_content' => 'Default content for '.$name.'.',
            'default_priority' => 'important',
            'default_acknowledgment_required' => true,
            'status' => $active,
            'created_by_user_id' => $owner->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function noticeData(Team $team, NoticeCategory $category, NoticeTemplate $template, string $title = 'Published from Template'): array
    {
        return [
            'audience_type' => NoticeAudienceType::Team,
            'notice_category_id' => $category->id,
            'notice_template_id' => $template->id,
            'team_id' => $team->id,
            'employee_ids' => [],
            'title' => $title,
            'content' => 'Independent Notice content.',
            'priority' => 'normal',
            'published_at' => '2026-08-22 10:00:00',
            'expires_at' => null,
            'acknowledgment_required' => false,
        ];
    }

    /** @return array{User, User, User, Team} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team),
            $this->user(EmployeeRole::Staff, $team),
            $this->user(EmployeeRole::Manager, $team),
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
