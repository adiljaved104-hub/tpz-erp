<?php

namespace Tests\Feature\Notifications;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\AuthenticationOtpPurpose;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\NotificationRulePermission;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\NotificationRules\Pages\ListNotificationRules;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\AuthenticationOtpNotification;
use App\Services\AuthenticationOtpService;
use App\Services\Authorization\NotificationRuleAuthorization;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Notifications\NotificationRuleService;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_complete_and_bootstrap_is_idempotent(): void
    {
        $this->assertDatabaseHas('notification_rules', ['event_key' => 'task.assigned', 'enabled' => true, 'in_app_enabled' => true, 'email_enabled' => true]);
        $this->assertDatabaseHas('notification_rules', ['event_key' => 'warranty.due_soon', 'threshold_value' => 3, 'threshold_unit' => 'days']);
        $this->assertDatabaseHas('notification_rules', ['event_key' => 'inventory.low_stock', 'threshold_value' => null]);
        $this->assertDatabaseHas('notification_rules', ['event_key' => 'hr.warning_issued']);
        $count = NotificationRule::query()->count();

        app(NotificationRuleService::class)->ensureDefaults();
        app(NotificationRuleService::class)->ensureDefaults();

        $this->assertSame($count, NotificationRule::query()->count());
        $this->assertGreaterThanOrEqual(12, $count);
    }

    public function test_owner_admin_defaults_and_employee_overrides_are_respected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(NotificationRuleAuthorization::class);

        $this->assertTrue($authorization->allows($owner, NotificationRulePermission::Manage));
        $this->assertTrue($authorization->allows($admin, NotificationRulePermission::Manage));
        $this->assertFalse($authorization->allows($staff, NotificationRulePermission::View));

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => NotificationRulePermission::View->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
        $this->assertTrue($authorization->allows($staff, NotificationRulePermission::View));
    }

    public function test_channels_and_global_email_setting_control_task_delivery(): void
    {
        Queue::fake();
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $rule = NotificationRule::query()->where('event_key', 'task.assigned')->firstOrFail();
        $service = app(NotificationRuleService::class);

        $service->update($rule, $this->ruleData($rule, ['email_enabled' => false]), $owner);
        $this->task($owner, $staff);
        $this->assertSame(1, $staff->notifications()->where('type', 'task.assigned')->count());
        Queue::assertNotPushed(SendQueuedNotifications::class);

        $staff->notifications()->delete();
        $service->update($rule->refresh(), $this->ruleData($rule->refresh(), ['in_app_enabled' => false, 'email_enabled' => true]), $owner);
        app(EmailConfigurationService::class)->save($this->emailSettings(), $owner);
        $this->task($owner, $staff);
        $this->assertSame(0, $staff->notifications()->where('type', 'task.assigned')->count());
        Queue::assertPushed(SendQueuedNotifications::class);

        Queue::fake();
        $service->update($rule->refresh(), $this->ruleData($rule->refresh(), ['in_app_enabled' => true, 'email_enabled' => true]), $owner);
        app(EmailConfigurationService::class)->save(array_replace($this->emailSettings(), ['enabled' => false]), $owner);
        $this->task($owner, $staff);
        $this->assertSame(1, $staff->notifications()->where('type', 'task.assigned')->count());
        Queue::assertNotPushed(SendQueuedNotifications::class);
    }

    public function test_disabled_rule_and_invalid_zero_channel_configuration_are_enforced_server_side(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $rule = NotificationRule::query()->where('event_key', 'task.assigned')->firstOrFail();
        $service = app(NotificationRuleService::class);

        $service->update($rule, $this->ruleData($rule, ['enabled' => false]), $owner);
        $this->task($owner, $staff);
        $this->assertSame(0, $staff->notifications()->where('type', 'task.assigned')->count());

        $this->expectException(ValidationException::class);
        $service->update($rule->refresh(), $this->ruleData($rule->refresh(), ['enabled' => true, 'in_app_enabled' => false, 'email_enabled' => false]), $owner);
    }

    public function test_rule_update_is_audited_without_sensitive_email_configuration(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $rule = NotificationRule::query()->where('event_key', 'warranty.due_soon')->firstOrFail();

        app(NotificationRuleService::class)->update($rule, $this->ruleData($rule, ['threshold_value' => 5]), $owner);

        $log = DB::table('activity_logs')->where('event', 'notification_rule.updated')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('warranty.due_soon', $log->properties);
        $this->assertStringNotContainsString('smtp', strtolower($log->properties));
    }

    public function test_security_otp_delivery_is_outside_operational_rules(): void
    {
        Notification::fake();
        NotificationRule::query()->update(['enabled' => false]);
        $user = $this->user(EmployeeRole::Staff);

        app(AuthenticationOtpService::class)->issue($user, AuthenticationOtpPurpose::Login, '127.0.0.1');

        Notification::assertSentTo($user, AuthenticationOtpNotification::class);
    }

    public function test_resource_table_and_authorization_render_correctly(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);

        $this->actingAs($owner)->get(NotificationRuleResource::getUrl())->assertOk()->assertSee('Task Assigned');
        $this->actingAs($staff)->get(NotificationRuleResource::getUrl())->assertForbidden();
    }

    public function test_table_toggle_persists_through_service_and_category_filter_works(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $rule = NotificationRule::query()->where('event_key', 'task.assigned')->firstOrFail();

        Livewire::actingAs($owner)->test(ListNotificationRules::class)
            ->call('updateTableColumnState', 'email_enabled', (string) $rule->id, false)
            ->filterTable('category', 'HR')
            ->assertCanSeeTableRecords(NotificationRule::query()->where('category', 'HR')->get())
            ->assertCanNotSeeTableRecords([$rule]);

        $this->assertFalse($rule->refresh()->email_enabled);
        $this->assertDatabaseHas('activity_logs', ['event' => 'notification_rule.updated', 'subject_type' => 'notification_rule', 'subject_id' => $rule->id]);
    }

    /** @return array<string, mixed> */
    private function ruleData(NotificationRule $rule, array $replace = []): array
    {
        return array_replace([
            'enabled' => $rule->enabled,
            'in_app_enabled' => $rule->in_app_enabled,
            'email_enabled' => $rule->email_enabled,
            'recipient_strategy' => $rule->recipient_strategy,
            'threshold_value' => $rule->threshold_value,
        ], $replace);
    }

    private function task(User $owner, User $staff): void
    {
        app(TaskService::class)->create(new CreateTaskData(
            title: 'Notification rule task '.Str::random(6), description: null, priority: TaskPriority::Normal,
            assignedEmployeeId: null, assignedTeamId: null, dueAt: null, followUpAt: null,
            linkedType: null, linkedRecordId: null, idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: [$staff->employee->id], assignmentMode: TaskAssignmentMode::SingleEmployee,
        ), $owner);
    }

    /** @return array<string, mixed> */
    private function emailSettings(): array
    {
        return [
            'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'notifications@example.test', 'smtp_password' => 'secret',
            'from_email' => 'notifications@example.test', 'from_name' => 'ERP',
        ];
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
