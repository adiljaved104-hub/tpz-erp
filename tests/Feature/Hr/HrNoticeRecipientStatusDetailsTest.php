<?php

namespace Tests\Feature\Hr;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\PeoplePermission;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\HrNotices\Pages\ViewHrNotice;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\HrNotice;
use App\Models\Team;
use App\Models\User;
use App\Services\Hr\HrNoticeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HrNoticeRecipientStatusDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_sees_recipient_details_matching_aggregate_progress_without_mutating_history(): void
    {
        [$admin, $asif, $ahmad, $zaid] = $this->people();
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => PeoplePermission::EmployeeView->value,
            'effect' => 'allow',
            'granted_by_user_id' => $admin->id,
            'reason' => 'Recipient status link coverage',
        ]);
        $notice = $this->notice($admin, [$asif, $ahmad, $zaid], true);

        CarbonImmutable::setTestNow('2026-08-22 11:00:00 UTC');
        app(HrNoticeService::class)->markRead($notice, $asif);
        app(HrNoticeService::class)->acknowledge($notice, $asif);
        CarbonImmutable::setTestNow('2026-08-22 12:00:00 UTC');
        app(HrNoticeService::class)->markRead($notice, $zaid);
        CarbonImmutable::setTestNow();

        $recipientFingerprint = $notice->recipients()->orderBy('id')->get()->toJson();
        $acknowledgmentFingerprint = $notice->acknowledgments()->orderBy('id')->get()->toJson();
        $asifUrl = EmployeeResource::getUrl('view', ['record' => $asif->employee]);

        $component = Livewire::actingAs($admin)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Recipients')
            ->assertSee('Read')
            ->assertSee('2 / 3')
            ->assertSee('Acknowledged')
            ->assertSee('1 / 3')
            ->assertSee('Recipient Status')
            ->assertSee('Asif')
            ->assertSee('Ahmad')
            ->assertSee('Zaid')
            ->assertSee('Unread')
            ->assertSee('Pending')
            ->assertSee('22 Aug 2026, 11:00 AM')
            ->assertSee('22 Aug 2026, 12:00 PM');

        $this->assertStringContainsString($asifUrl, html_entity_decode($component->html()));

        $this->assertSame($recipientFingerprint, $notice->recipients()->orderBy('id')->get()->toJson());
        $this->assertSame($acknowledgmentFingerprint, $notice->acknowledgments()->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('hr_notice_recipients', 3);
        $this->assertDatabaseCount('hr_acknowledgments', 3);
    }

    public function test_ordinary_recipient_cannot_see_other_recipient_statuses(): void
    {
        [$admin, $asif, $ahmad] = $this->people();
        $notice = $this->notice($admin, [$asif, $ahmad], true);

        Livewire::actingAs($asif)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Read State')
            ->assertSee('Your Acknowledgment')
            ->assertDontSee('Recipient Status')
            ->assertDontSee('Ahmad');
    }

    public function test_non_required_acknowledgment_is_neutral_and_employee_links_follow_employee_authorization(): void
    {
        [$admin, $asif, $ahmad] = $this->people();
        $notice = $this->notice($admin, [$asif, $ahmad], false);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id,
            'permission_key' => PeoplePermission::EmployeeView->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $admin->id,
            'reason' => 'Recipient-status link authorization test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $asifUrl = EmployeeResource::getUrl('view', ['record' => $asif->employee]);
        Livewire::actingAs($admin)->test(ViewHrNotice::class, ['record' => $notice->id])
            ->assertSee('Recipient Status')
            ->assertSee('Not Required')
            ->assertDontSee('Pending')
            ->assertDontSee($asifUrl, escape: false);
    }

    /** @return array{User, User, User, User} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Admin, $team, 'Adil'),
            $this->user(EmployeeRole::Staff, $team, 'Asif'),
            $this->user(EmployeeRole::Staff, $team, 'Ahmad'),
            $this->user(EmployeeRole::Staff, $team, 'Zaid'),
        ];
    }

    private function user(EmployeeRole $role, Team $team, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name,
            'email' => $user->email,
            'team_id' => $team->id,
            'status' => true,
        ]);

        return $user->refresh();
    }

    /** @param array<int, User> $recipients */
    private function notice(User $admin, array $recipients, bool $acknowledgmentRequired): HrNotice
    {
        return app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'team_id' => null,
            'employee_ids' => array_map(fn (User $user): int => $user->employee->id, $recipients),
            'title' => 'Operations Update',
            'content' => 'Recipient status detail coverage.',
            'priority' => 'normal',
            'published_at' => '2026-08-22 10:00:00',
            'expires_at' => null,
            'acknowledgment_required' => $acknowledgmentRequired,
        ], $admin);
    }
}
