<?php

namespace Tests\Feature\Notifications;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\Team;
use App\Models\User;
use App\Models\WarningCategory;
use App\Notifications\CriticalAlertMailNotification;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Notifications\HrRecordNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HrWarningsNoticesCriticalAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_alert_is_emailed_only_to_warned_employee_with_acknowledgment_context(): void
    {
        Notification::fake();
        [$owner, $staff, $other] = $this->people();
        $this->enableEmail($owner);

        $warning = $this->warning($owner, $staff, true);

        Notification::assertSentTo($staff, CriticalAlertMailNotification::class, function ($notification) use ($staff, $warning): bool {
            $lines = implode(' ', $notification->toMail($staff)->introLines);

            return str_contains($lines, $warning->reference)
                && str_contains($lines, 'Category: Attendance')
                && str_contains($lines, 'Issue date: 21 Aug 2026')
                && str_contains($lines, 'Acknowledgment: Required');
        });
        Notification::assertNotSentTo($owner, CriticalAlertMailNotification::class);
        Notification::assertNotSentTo($other, CriticalAlertMailNotification::class);
    }

    public function test_notice_email_uses_only_materialized_recipients_and_safe_published_content(): void
    {
        Notification::fake();
        [$owner, $staff, $other] = $this->people();
        $this->enableEmail($owner);

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected', 'team_id' => null,
            'employee_ids' => [$staff->employee->id],
            'title' => 'Attendance policy update',
            'content' => 'Please review the revised attendance policy before Monday.',
            'priority' => 'important', 'published_at' => '2026-08-21 10:00:00',
            'expires_at' => null, 'acknowledgment_required' => true,
        ], $owner);

        $this->assertDatabaseHas('hr_notice_recipients', [
            'hr_notice_id' => $notice->id,
            'employee_id' => $staff->employee->id,
        ]);
        Notification::assertSentTo($staff, CriticalAlertMailNotification::class, function ($notification) use ($staff, $notice): bool {
            $lines = implode(' ', $notification->toMail($staff)->introLines);

            return str_contains($lines, $notice->reference)
                && str_contains($lines, 'Title: Attendance policy update')
                && str_contains($lines, 'Priority: Important')
                && str_contains($lines, 'Acknowledgment: Required');
        });
        Notification::assertNotSentTo($owner, CriticalAlertMailNotification::class);
        Notification::assertNotSentTo($other, CriticalAlertMailNotification::class);
    }

    public function test_archived_notice_is_not_dispatched_and_non_recipient_fails_job_time_authorization(): void
    {
        Notification::fake();
        [$owner, $staff, $other] = $this->people();
        $this->enableEmail($owner);
        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected', 'team_id' => null,
            'employee_ids' => [$staff->employee->id], 'title' => 'Published notice',
            'content' => 'Published content.', 'priority' => 'normal',
            'published_at' => '2026-08-21 10:00:00', 'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);
        $mail = null;
        Notification::assertSentTo($staff, CriticalAlertMailNotification::class, function ($notification) use (&$mail): bool {
            $mail = $notification;

            return true;
        });

        $this->assertFalse($mail->shouldSend($other, 'mail'));

        Notification::fake();
        $notice->forceFill(['status' => 'archived', 'archived_at' => now(), 'archived_by_user_id' => $owner->id])->save();
        app(HrRecordNotificationDispatcher::class)->noticePublished($notice->refresh());
        Notification::assertNothingSent();
    }

    public function test_warning_and_notice_dispatches_are_deduplicated(): void
    {
        Queue::fake();
        [$owner, $staff] = $this->people();
        $this->enableEmail($owner);

        $warning = $this->warning($owner, $staff, false);
        app(HrRecordNotificationDispatcher::class)->warningIssued($warning);

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected', 'team_id' => null,
            'employee_ids' => [$staff->employee->id], 'title' => 'One publication',
            'content' => 'This publication must alert once.', 'priority' => 'normal',
            'published_at' => '2026-08-21 10:00:00', 'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);
        app(HrRecordNotificationDispatcher::class)->noticePublished($notice);

        $this->assertSame(1, $staff->notifications()->where('type', 'warning.issued')->count());
        $this->assertSame(1, $staff->notifications()->where('type', 'notice.published')->count());
        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    public function test_disabled_email_keeps_in_app_warning_and_notice_alerts(): void
    {
        Queue::fake();
        [$owner, $staff] = $this->people();
        app(EmailConfigurationService::class)->save([
            'enabled' => false, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'mailer@example.test',
            'smtp_password' => 'test-secret', 'from_email' => 'alerts@example.test',
            'from_name' => 'ERP Alerts',
        ], $owner);

        $this->warning($owner, $staff, false);
        app(HrNoticeService::class)->publish([
            'audience_type' => 'selected', 'team_id' => null,
            'employee_ids' => [$staff->employee->id], 'title' => 'In-app only notice',
            'content' => 'Email delivery is disabled.', 'priority' => 'normal',
            'published_at' => '2026-08-21 10:00:00', 'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);

        $this->assertSame(1, $staff->notifications()->where('type', 'warning.issued')->count());
        $this->assertSame(1, $staff->notifications()->where('type', 'notice.published')->count());
        Queue::assertNotPushed(SendQueuedNotifications::class);
    }

    private function warning(User $owner, User $staff, bool $acknowledgmentRequired): EmployeeWarning
    {
        $category = WarningCategory::query()->firstOrCreate(
            ['normalized_name' => 'attendance'],
            ['name' => 'Attendance', 'status' => true, 'created_by_user_id' => $owner->id],
        );

        return app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id, 'warning_level' => 'written',
            'warning_category_id' => $category->id, 'title' => 'Attendance concern',
            'description' => 'A concise warning reason.', 'issued_date' => '2026-08-21',
            'acknowledgment_required' => $acknowledgmentRequired,
        ], $owner);
    }

    private function enableEmail(User $owner): void
    {
        app(EmailConfigurationService::class)->save([
            'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'mailer@example.test',
            'smtp_password' => 'test-secret', 'from_email' => 'alerts@example.test',
            'from_name' => 'ERP Alerts',
        ], $owner);
    }

    /** @return array{User, User, User} */
    private function people(): array
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);

        return [
            $this->user(EmployeeRole::Owner, $team, 'Owner'),
            $this->user(EmployeeRole::Staff, $team, 'Staff'),
            $this->user(EmployeeRole::Staff, $team, 'Other Staff'),
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
}
