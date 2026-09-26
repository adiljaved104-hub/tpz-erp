<?php

namespace Tests\Feature\Mobile;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Tasks\CreateTaskData;
use App\Enums\EmployeeRole;
use App\Enums\TaskPriority;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaskCompletionSubmission;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationService;
use App\Services\Mobile\NotificationTarget;
use App\Services\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePurchasingWorkTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_receiving_uses_domain_posting_and_grn_endpoints_protect_costs(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        [$purchase, $product] = $this->approvedPurchase($owner, 2, '80.0000');
        $line = $purchase->items->sole();

        $response = $this->as($owner)->postJson('/api/mobile/v1/workspace/purchases/'.$purchase->id.'/receive', [
            'received_at' => now()->toIso8601String(),
            'idempotency_key' => (string) Str::uuid(),
            'supplier_delivery_note' => 'DN-MOBILE-001',
            'items' => [[
                'purchase_item_id' => $line->id,
                'accepted_quantity' => 1,
                'damaged_quantity' => 1,
                'rejected_quantity' => 0,
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'fully_received');

        $receipt = PurchaseReceipt::query()->sole();
        $this->assertSame($receipt->id, $response->json('data.receipts.0.id'));
        $this->assertSame(1, ProductInventory::query()->where('product_id', $product->id)->sole()->available_quantity);
        $this->assertSame(1, ProductInventory::query()->where('product_id', $product->id)->sole()->damaged_quantity);
        $this->assertSame(1, StockMovement::query()->count());

        $ownerDetail = $this->as($owner)->getJson('/api/mobile/v1/workspace/purchase-receipts/'.$receipt->id)->assertOk();
        $ownerDetail->assertJsonPath('data.fields.purchase_reference', $purchase->reference)
            ->assertJsonPath('data.fields.accepted_quantity', 1)
            ->assertJsonPath('data.fields.damaged_quantity', 1);
        $this->assertSame('80.0000', $ownerDetail->json('data.items.0.inventory_unit_cost'));

        $managerList = $this->as($manager)->getJson('/api/mobile/v1/workspace/purchase-receipts?q='.$receipt->reference)->assertOk();
        $this->assertSame($receipt->id, $managerList->json('data.0.id'));
        $managerDetail = $this->as($manager)->getJson('/api/mobile/v1/workspace/purchase-receipts/'.$receipt->id)->assertOk();
        $this->assertArrayNotHasKey('inventory_unit_cost', $managerDetail->json('data.items.0'));
        $this->as($staff)->getJson('/api/mobile/v1/workspace/purchase-receipts')->assertForbidden();

        $search = $this->as($owner)->getJson('/api/mobile/v1/workspace/search?q='.$receipt->reference)->assertOk();
        $item = collect($search->json('data'))->flatMap(fn ($group) => $group['items'])
            ->firstWhere('target.module', 'purchase-receipts');
        $this->assertSame($receipt->id, $item['target']['id']);
        $this->assertStringNotContainsString('"url"', $search->getContent());
    }

    public function test_purchase_open_filter_excludes_terminal_records(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        [$open] = $this->approvedPurchase($owner, 1, '25.0000');
        $cancelled = Purchase::factory()->create([
            'status' => 'cancelled',
            'created_by_user_id' => $owner->id,
        ]);

        $response = $this->as($owner)->getJson('/api/mobile/v1/workspace/purchases?filter=open')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains($open->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_task_due_scope_cancel_reopen_and_invalid_transitions_use_task_service(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $assignee = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $task = app(TaskService::class)->create(new CreateTaskData(
            'Mobile purchasing follow-up',
            'Confirm supplier delivery',
            TaskPriority::High,
            $assignee->employee->id,
            null,
            now()->subDay()->toDateTimeString(),
            null,
            null,
            null,
            (string) Str::uuid(),
        ), $owner);

        $detail = $this->as($assignee)->getJson('/api/mobile/v1/workspace/tasks/'.$task->id)->assertOk();
        $this->assertStringContainsString('overdue', $detail->json('data.fields.due_context'));
        $this->assertTrue($detail->json('data.fields.overdue'));
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/tasks/'.$task->id)->assertForbidden();
        $this->as($outsider)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/start')->assertForbidden();
        $this->as($assignee)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/cancel', ['note' => 'Not allowed'])->assertForbidden();
        $this->as($owner)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/reopen', ['note' => 'Too early'])->assertUnprocessable();

        $this->as($assignee)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/start')->assertOk();
        $this->as($assignee)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/complete', ['note' => 'Done'])->assertOk();
        $submission = TaskCompletionSubmission::query()->sole();
        $this->as($owner)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/confirm-'.$submission->id)->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $completed = $this->as($owner)->getJson('/api/mobile/v1/workspace/tasks/'.$task->id)->assertOk();
        $this->assertContains('reopen', collect($completed->json('data.actions'))->pluck('key'));
        $this->as($owner)->postJson('/api/mobile/v1/workspace/tasks/'.$task->id.'/reopen', ['note' => 'Correction required'])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $cancelled = app(TaskService::class)->create(new CreateTaskData(
            'Cancel mobile task', null, TaskPriority::Normal, $assignee->employee->id,
            null, null, null, null, null, (string) Str::uuid(),
        ), $owner);
        $this->as($owner)->postJson('/api/mobile/v1/workspace/tasks/'.$cancelled->id.'/cancel', ['note' => 'No longer required'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_chat_detail_read_send_and_targets_remain_participant_private(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $participant = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($owner, $participant->employee);
        $first = app(ConversationMessageService::class)->send($owner, $conversation, 'Supplier delivery is ready.');

        $inbox = $this->as($participant)->getJson('/api/mobile/v1/chat?filter=direct')->assertOk();
        $this->assertContains($conversation->id, collect($inbox->json('data'))->pluck('id'));
        $detail = $this->as($participant)->getJson('/api/mobile/v1/chat/'.$conversation->id)->assertOk();
        $this->assertCount(2, $detail->json('data.participants'));
        $this->assertSame($first->id, $detail->json('data.latest_message.id'));

        $messages = $this->as($participant)->getJson('/api/mobile/v1/chat/'.$conversation->id.'/messages')->assertOk();
        $this->assertSame($first->id, $messages->json('data.0.id'));
        $this->as($participant)->postJson('/api/mobile/v1/chat/'.$conversation->id.'/read', ['message_id' => $first->id])
            ->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->as($participant)->postJson('/api/mobile/v1/chat/'.$conversation->id.'/messages', ['body' => 'Received, thank you.'])
            ->assertCreated();

        $this->as($outsider)->getJson('/api/mobile/v1/chat/'.$conversation->id)->assertForbidden();
        $this->as($outsider)->getJson('/api/mobile/v1/chat/'.$conversation->id.'/messages')->assertForbidden();
        $target = app(NotificationTarget::class);
        $this->assertSame(['module' => 'chat', 'id' => $conversation->id],
            $target->resolve($participant, ['target_type' => 'conversation', 'target_id' => $conversation->id]));
        $this->assertNull($target->resolve($outsider, ['target_type' => 'conversation', 'target_id' => $conversation->id]));
    }

    /** @return array{Purchase, Product} */
    private function approvedPurchase(User $owner, int $quantity, string $cost): array
    {
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id,
            Warehouse::factory()->create()->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, $quantity, $cost)],
            'INV-'.Str::upper(Str::random(8)),
            now()->toDateString(),
        ), $owner);
        $purchase = app(ApprovePurchase::class)->handle(
            $purchase,
            new ApprovePurchaseData('Approved for mobile receiving.', true),
            $owner,
        );

        return [$purchase->load('items'), $product];
    }

    private function user(EmployeeRole $role): User
    {
        $email = 'mobile-work-'.Str::lower(Str::random(12)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email, 'status' => true]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile purchasing work test')->plainTextToken);
    }
}
