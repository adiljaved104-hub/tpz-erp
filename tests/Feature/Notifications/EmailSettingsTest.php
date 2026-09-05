<?php

namespace Tests\Feature\Notifications;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmailSettingsPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Filament\Pages\Administration\EmailDelivery;
use App\Models\EmailSetting;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Authorization\EmailSettingsAuthorization;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_defaults_staff_denial_and_employee_override_are_respected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(EmailSettingsAuthorization::class);

        $this->assertTrue($authorization->allows($owner, EmailSettingsPermission::Manage));
        $this->assertTrue($authorization->allows($admin, EmailSettingsPermission::Manage));
        $this->assertFalse($authorization->allows($staff, EmailSettingsPermission::View));

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id, 'permission_key' => EmailSettingsPermission::View->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertTrue($authorization->allows($staff, EmailSettingsPermission::View));

        EmployeePermissionOverride::query()->create([
            'employee_id' => $admin->employee->id, 'permission_key' => EmailSettingsPermission::Manage->value,
            'effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);
        $this->assertFalse($authorization->allows($admin, EmailSettingsPermission::Manage));
        $this->assertTrue($authorization->allows($owner, EmailSettingsPermission::Manage));
    }

    public function test_password_is_encrypted_hidden_and_blank_update_preserves_it(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(EmailConfigurationService::class);
        $settings = $service->save($this->settingsData('super-secret'), $owner);

        $raw = DB::table('email_settings')->value('smtp_password_encrypted');
        $this->assertNotSame('super-secret', $raw);
        $this->assertSame('super-secret', $settings->smtp_password_encrypted);
        $this->assertArrayNotHasKey('smtp_password_encrypted', $settings->toArray());

        $service->save($this->settingsData(''), $owner);
        $this->assertSame('super-secret', EmailSetting::query()->findOrFail(1)->smtp_password_encrypted);
        $this->assertStringNotContainsString('super-secret', DB::table('activity_logs')->pluck('properties')->implode(' '));

        Livewire::actingAs($owner)->test(EmailDelivery::class)
            ->assertSet('smtpPassword', '')
            ->assertDontSee('super-secret');
    }

    public function test_database_settings_override_config_and_environment_fallback_remains_available(): void
    {
        config([
            'mail.notifications_enabled' => true, 'mail.environment_fallback.default' => 'smtp',
            'mail.environment_fallback.host' => 'env.smtp.test', 'mail.environment_fallback.port' => 2525,
            'mail.environment_fallback.username' => 'env-user', 'mail.environment_fallback.password' => 'env-pass',
            'mail.environment_fallback.from_address' => 'env@example.test', 'mail.environment_fallback.from_name' => 'ERP',
        ]);
        $service = app(EmailConfigurationService::class);
        $this->assertTrue($service->configured());
        $this->assertTrue($service->apply());
        $this->assertSame('env.smtp.test', config('mail.mailers.smtp.host'));

        $owner = $this->user(EmployeeRole::Owner);
        $service->save($this->settingsData('database-secret'), $owner);
        $this->assertTrue($service->apply());
        $this->assertSame('db.smtp.test', config('mail.mailers.smtp.host'));
        $this->assertSame('notifications@techpointzone.com', config('mail.from.address'));
    }

    public function test_disabling_email_preserves_in_app_task_notification_without_queueing_mail(): void
    {
        Queue::fake();
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        app(EmailConfigurationService::class)->save(array_replace($this->settingsData('super-secret'), ['enabled' => false]), $owner);

        $task = app(TaskService::class)->create(new CreateTaskData(
            title: 'In-app only', description: null, priority: TaskPriority::Normal,
            assignedEmployeeId: null, assignedTeamId: null, dueAt: null, followUpAt: null,
            linkedType: null, linkedRecordId: null, idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: [$staff->employee->id], assignmentMode: TaskAssignmentMode::SingleEmployee,
        ), $owner);

        $this->assertSame(1, $staff->notifications()->where('type', 'task.assigned')->count());
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        Queue::assertNotPushed(SendQueuedNotifications::class);
    }

    public function test_test_email_action_is_authorized_and_invalid_recipient_is_rejected(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        app(EmailConfigurationService::class)->save($this->settingsData('super-secret'), $owner);

        Livewire::actingAs($owner)->test(EmailDelivery::class)
            ->set('recipientEmail', 'invalid-email')
            ->call('sendTestEmail')
            ->assertHasErrors(['recipientEmail']);
        $this->actingAs($staff)->get(EmailDelivery::getUrl())->assertForbidden();
    }

    public function test_smtp_failure_is_safe_and_does_not_expose_credentials(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        config(['mail.environment_fallback.host' => null, 'mail.environment_fallback.username' => null, 'mail.environment_fallback.password' => null]);

        Livewire::actingAs($owner)->test(EmailDelivery::class)
            ->set('recipientEmail', 'owner@example.test')
            ->call('sendTestEmail')
            ->assertNotified('Unable to send the test email. Check the SMTP configuration.');
    }

    /** @return array<string, mixed> */
    private function settingsData(string $password): array
    {
        return [
            'enabled' => true, 'smtp_host' => 'db.smtp.test', 'smtp_port' => 587, 'encryption' => 'tls',
            'smtp_username' => 'notifications@techpointzone.com', 'smtp_password' => $password,
            'from_email' => 'notifications@techpointzone.com', 'from_name' => 'Tech Point Zone ERP',
        ];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
