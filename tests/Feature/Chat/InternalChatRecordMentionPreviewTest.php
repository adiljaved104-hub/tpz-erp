<?php

namespace Tests\Feature\Chat;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Filament\Pages\Chat;
use App\Models\ConversationMessage;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Chat\ChatRecordMentionService;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationService;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class InternalChatRecordMentionPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_record_only_mixed_and_normal_previews_use_authorized_labels(): void
    {
        [$admin] = $this->employee(EmployeeRole::Admin);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        $order = $this->order($admin, 'SO-2026-000015');
        $conversation = app(ConversationService::class)->direct($admin, $peer);
        $mentions = app(ChatRecordMentionService::class);

        $recordOnly = app(ConversationMessageService::class)->send(
            $admin,
            $conversation,
            $mentions->appendToken($admin, '', 'order', $order->id),
        );
        $mixed = app(ConversationMessageService::class)->send(
            $admin,
            $conversation,
            $mentions->appendToken($admin, 'Please check', 'order', $order->id),
        );
        $normal = app(ConversationMessageService::class)->send($admin, $conversation, 'Normal update');

        $previews = $mentions->previewsForMessages(collect([$recordOnly, $mixed, $normal]), $admin);

        $this->assertSame('Order — SO-2026-000015', $previews[$recordOnly->id]);
        $this->assertSame('Please check Order — SO-2026-000015', $previews[$mixed->id]);
        $this->assertSame('Normal update', $previews[$normal->id]);
        $recordSegment = collect($mentions->segmentsForMessages(collect([$mixed]), $admin)[$mixed->id])->firstWhere('type', 'record');
        $this->assertSame('Order — SO-2026-000015', $recordSegment['label']);
        $this->assertStringContainsString('/admin/orders/', $recordSegment['url']);
    }

    public function test_inbox_and_message_hide_reference_after_source_permission_revocation(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin, $adminEmployee] = $this->employee(EmployeeRole::Admin);
        $order = $this->order($owner, 'SO-2026-000015');
        $conversation = app(ConversationService::class)->direct($admin, $owner->employee);
        $mentions = app(ChatRecordMentionService::class);
        app(ConversationMessageService::class)->send(
            $admin,
            $conversation,
            $mentions->appendToken($admin, 'Please check', 'order', $order->id),
        );

        $this->actingAs($admin);
        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->assertSee('Please check Order — SO-2026-000015')
            ->assertDontSee('[Business record]');

        EmployeePermissionOverride::query()->create([
            'employee_id' => $adminEmployee->id,
            'permission_key' => OrderPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Preview authorization regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($adminEmployee->id);

        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->assertSee('Restricted Record')
            ->assertDontSee('SO-2026-000015');
    }

    public function test_multiple_order_mentions_are_resolved_in_one_batched_order_query(): void
    {
        [$admin] = $this->employee(EmployeeRole::Admin);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        $first = $this->order($admin, 'SO-2026-000015');
        $second = $this->order($admin, 'SO-2026-000016');
        $conversation = app(ConversationService::class)->direct($admin, $peer);
        $mentions = app(ChatRecordMentionService::class);
        $messages = collect([$first, $second])->map(fn (Order $order): ConversationMessage => app(ConversationMessageService::class)->send(
            $admin,
            $conversation,
            $mentions->appendToken($admin, 'Review', 'order', $order->id),
        ));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $previews = $mentions->previewsForMessages($messages, $admin);
        $orderQueries = collect(DB::getQueryLog())->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from "orders"') || str_contains($sql, 'from `orders`');
        });
        DB::disableQueryLog();

        $this->assertCount(2, $previews);
        $this->assertCount(1, $orderQueries);
    }

    public function test_notification_preview_never_stores_raw_tokens_and_reauthorizes_the_record_label(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin, $adminEmployee] = $this->employee(EmployeeRole::Admin);
        $order = $this->order($owner, 'SO-2026-000015');
        $conversation = app(ConversationService::class)->direct($owner, $adminEmployee);
        $mentions = app(ChatRecordMentionService::class);

        app(ConversationMessageService::class)->send(
            $owner,
            $conversation,
            $mentions->appendToken($owner, 'Please check', 'order', $order->id),
        );

        $notifications = $admin->notifications()->get();
        $stored = (string) data_get($notifications->sole()->data, 'message');
        $this->assertStringNotContainsString('[[erp-record:', $stored);
        $this->assertSame(
            'Please check Order — SO-2026-000015',
            app(NotificationInboxService::class)->displayMessages($notifications, $admin)[$notifications->sole()->id],
        );

        EmployeePermissionOverride::query()->create([
            'employee_id' => $adminEmployee->id,
            'permission_key' => OrderPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Notification preview authorization regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($adminEmployee->id);

        $this->assertSame(
            'Please check Restricted Record',
            app(NotificationInboxService::class)->displayMessages($notifications, $admin)[$notifications->sole()->id],
        );
    }

    /** @return array{User, Employee} */
    private function employee(EmployeeRole $role): array
    {
        $employee = Employee::factory()->role($role)->create();

        return [$employee->user, $employee];
    }

    private function order(User $creator, string $reference): Order
    {
        $warehouse = Warehouse::factory()->create(['status' => true]);

        return Order::query()->create([
            'reference' => $reference,
            'warehouse_id' => $warehouse->id,
            'order_date' => '2026-08-22',
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $creator->id,
        ]);
    }
}
