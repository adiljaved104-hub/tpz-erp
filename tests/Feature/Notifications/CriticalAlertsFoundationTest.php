<?php

namespace Tests\Feature\Notifications;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Filament\Pages\Administration\EmailDelivery;
use App\Models\Employee;
use App\Models\ProductInventory;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Notifications\CriticalAlertMailNotification;
use App\Notifications\SmtpTestNotification;
use App\Observers\ProductInventoryObserver;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CriticalAlertsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_assignment_routes_queued_email_to_the_assigned_employee(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        app(EmailConfigurationService::class)->save([
            'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'mailer@example.test', 'smtp_password' => 'test-secret',
            'from_email' => 'notifications@example.test', 'from_name' => 'ERP Test',
        ], $owner);

        $this->task($owner, $staff->employee->id);

        Notification::assertSentTo($staff, CriticalAlertMailNotification::class, fn ($notification, $channels): bool => $channels === ['mail']);
        Notification::assertNotSentTo($owner, CriticalAlertMailNotification::class);
    }

    public function test_missing_company_email_keeps_task_workflow_successful_without_queueing_mail(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        $staff->employee->update(['email' => 'not-an-email']);

        $task = $this->task($owner, $staff->employee->id);

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        Notification::assertNotSentTo($staff, CriticalAlertMailNotification::class);
    }

    public function test_low_and_out_of_stock_transitions_alert_once_without_financial_payload(): void
    {
        Queue::fake();
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $inventory = ProductInventory::factory()->create(['available_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => 9999]);

        $observer = app(ProductInventoryObserver::class);
        $inventory->update(['available_quantity' => 2]);
        $observer->updated($inventory);
        $inventory->update(['available_quantity' => 1]);
        $observer->updated($inventory);
        $inventory->update(['available_quantity' => 0]);
        $observer->updated($inventory);
        $inventory->update(['available_quantity' => 0]);
        $observer->updated($inventory);

        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.low_stock')->count());
        $this->assertSame(1, $owner->notifications()->where('type', 'inventory.out_of_stock')->count());
        $payload = $owner->notifications()->where('type', 'inventory.low_stock')->sole()->data;
        $this->assertArrayNotHasKey('average_cost', $payload);
        $this->assertArrayNotHasKey('cost', $payload);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
    }

    public function test_owner_can_queue_smtp_test_and_staff_cannot_access_page(): void
    {
        Notification::fake();
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $staff = $this->user(EmployeeRole::Staff, 'Staff');
        app(EmailConfigurationService::class)->save([
            'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
            'encryption' => 'tls', 'smtp_username' => 'mailer@example.test', 'smtp_password' => 'test-secret',
            'from_email' => 'notifications@example.test', 'from_name' => 'ERP Test',
        ], $owner);

        Livewire::actingAs($owner)->test(EmailDelivery::class)
            ->set('recipientEmail', 'admin@techpointzone.com')
            ->call('sendTestEmail')
            ->assertHasNoErrors();
        Notification::assertSentOnDemand(SmtpTestNotification::class);

        $this->actingAs($staff)->get(EmailDelivery::getUrl())->assertForbidden();
    }

    public function test_warranty_due_and_overdue_scheduler_alerts_are_deduplicated(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-08-23 10:00:00');
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $inventory = ProductInventory::factory()->create();
        $case = WarrantyRepair::query()->create([
            'reference' => 'WR-2026-TEST01', 'product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id,
            'quantity' => 1, 'source' => 'manual', 'issue_description' => 'Focused SLA test',
            'received_at' => now()->subDays(12), 'status' => 'received', 'assigned_to_user_id' => $owner->id,
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);

        $this->artisan('warranty:send-sla-notifications')->assertSuccessful();
        $this->artisan('warranty:send-sla-notifications')->assertSuccessful();
        $this->assertSame(1, $owner->notifications()->where('type', 'warranty.sla_due_soon')->count());

        CarbonImmutable::setTestNow('2026-08-26 10:00:00');
        $this->artisan('warranty:send-sla-notifications')->assertSuccessful();
        $this->assertSame(1, $owner->notifications()->where('type', 'warranty.sla_overdue')->count());
        $this->assertDatabaseHas('warranty_repairs', ['id' => $case->id, 'status' => 'received']);
        CarbonImmutable::setTestNow();
    }

    private function task(User $owner, int $employeeId): Task
    {
        return app(TaskService::class)->create(new CreateTaskData(
            title: 'Email alert task', description: null, priority: TaskPriority::Normal,
            assignedEmployeeId: null, assignedTeamId: null, dueAt: now()->addDay()->toDateTimeString(), followUpAt: null,
            linkedType: null, linkedRecordId: null, idempotencyKey: (string) Str::uuid(),
            assignedEmployeeIds: [$employeeId], assignmentMode: TaskAssignmentMode::SingleEmployee,
        ), $owner);
    }

    private function user(EmployeeRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create(['name' => $name, 'email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
