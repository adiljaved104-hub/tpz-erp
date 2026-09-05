<?php

namespace App\Services\Notifications;

use App\Enums\CustomerReturnPermission;
use App\Enums\InventoryPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Filament\Pages\Chat;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomerReturn;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\LeaveRequest;
use App\Models\ProductInventory;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Chat\ChatRecordMentionService;
use App\Services\Hr\HrScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class NotificationInboxService
{
    public function __construct(
        private readonly TaskAuthorization $taskAuthorization,
        private readonly HrScopeService $hrScope,
        private readonly HrRecordAuthorization $hrRecords,
        private readonly ChatAuthorization $chatAuthorization,
        private readonly CustomerReturnAuthorization $returnAuthorization,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly SafetClaimAuthorization $claimAuthorization,
        private readonly WarrantyRepairAuthorization $warrantyAuthorization,
        private readonly ChatRecordMentionService $recordMentions,
    ) {}

    public function query(User $user): Builder
    {
        return $user->notifications()->getQuery()->latest('created_at');
    }

    /**
     * Resolve Chat previews in one message query and one authorized query per
     * referenced record type. Non-Chat notifications keep their stored copy.
     *
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array<string, string>
     */
    public function displayMessages(Collection $notifications, User $user): array
    {
        $chatNotifications = $notifications->filter(fn (DatabaseNotification $notification): bool => ($notification->data['target_type'] ?? null) === 'conversation'
            && is_numeric($notification->data['message_id'] ?? null));

        $messages = ConversationMessage::query()
            ->whereKey($chatNotifications->pluck('data.message_id')->map(fn ($id): int => (int) $id)->unique())
            ->get();
        $previews = $this->recordMentions->previewsForMessages($messages, $user, 140);

        return $notifications->mapWithKeys(function (DatabaseNotification $notification) use ($previews): array {
            $messageId = $notification->data['message_id'] ?? null;
            $fallback = (string) ($notification->data['message'] ?? '');

            if (($notification->data['target_type'] ?? null) === 'conversation') {
                $fallback = preg_replace('/\[\[erp-record:[a-z_]+:\d+\]\]/', 'Restricted Record', $fallback) ?? 'Restricted Record';
            }

            return [(string) $notification->id => is_numeric($messageId)
                ? ($previews[(int) $messageId] ?? $fallback)
                : $fallback];
        })->all();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function own(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    public function markRead(User $user, string $id): void
    {
        $this->own($user, $id)->markAsRead();
    }

    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /** @return array{url: ?string, available: bool} */
    public function open(User $user, string $id): array
    {
        $notification = $this->own($user, $id);
        $notification->markAsRead();
        $data = $notification->data;

        if (! is_numeric($data['target_id'] ?? null)) {
            return ['url' => null, 'available' => false];
        }

        if (($data['target_type'] ?? null) === 'leave_request') {
            $request = LeaveRequest::query()->with('employee')->find((int) $data['target_id']);
            if (! $request instanceof LeaveRequest || ! $this->hrScope->canViewLeave($user, $request->employee)) {
                return ['url' => null, 'available' => false];
            }

            return ['url' => LeaveManagement::getUrl(), 'available' => true];
        }

        if (($data['target_type'] ?? null) === 'employee_warning') {
            $warning = EmployeeWarning::query()->find((int) $data['target_id']);
            if (! $warning instanceof EmployeeWarning || ! $this->hrRecords->canViewWarning($user, $warning)) {
                return ['url' => null, 'available' => false];
            }

            return ['url' => EmployeeWarningResource::getUrl('view', ['record' => $warning]), 'available' => true];
        }

        if (($data['target_type'] ?? null) === 'hr_notice') {
            $notice = HrNotice::query()->find((int) $data['target_id']);
            if (! $notice instanceof HrNotice || ! $this->hrRecords->canViewNotice($user, $notice)) {
                return ['url' => null, 'available' => false];
            }

            return ['url' => HrNoticeResource::getUrl('view', ['record' => $notice]), 'available' => true];
        }

        if (($data['target_type'] ?? null) === 'conversation') {
            $conversation = Conversation::query()->find((int) $data['target_id']);
            if (! $conversation instanceof Conversation || ! $this->chatAuthorization->canAccessConversation($user, $conversation)) {
                return ['url' => null, 'available' => false];
            }

            return ['url' => Chat::getUrl(['conversation' => $conversation->id]), 'available' => true];
        }

        if (($data['target_type'] ?? null) === 'product_inventory') {
            $record = ProductInventory::query()->find((int) $data['target_id']);

            return $record instanceof ProductInventory && $this->inventoryAuthorization->allows($user, InventoryPermission::View, $record)
                ? ['url' => ProductInventoryResource::getUrl('view', ['record' => $record]), 'available' => true]
                : ['url' => null, 'available' => false];
        }

        if (($data['target_type'] ?? null) === 'warranty_repair') {
            $record = WarrantyRepair::query()->find((int) $data['target_id']);

            return $record instanceof WarrantyRepair && $this->warrantyAuthorization->allows($user, WarrantyRepairPermission::View, $record)
                ? ['url' => WarrantyRepairResource::getUrl('view', ['record' => $record]), 'available' => true]
                : ['url' => null, 'available' => false];
        }

        if (($data['target_type'] ?? null) === 'safet_claim') {
            $record = SafetClaim::query()->find((int) $data['target_id']);

            return $record instanceof SafetClaim && $this->claimAuthorization->allows($user, SafetClaimPermission::View, $record)
                ? ['url' => SafetClaimResource::getUrl('view', ['record' => $record]), 'available' => true]
                : ['url' => null, 'available' => false];
        }

        if (($data['target_type'] ?? null) === 'customer_return') {
            $record = CustomerReturn::query()->find((int) $data['target_id']);

            return $record instanceof CustomerReturn && $this->returnAuthorization->allows($user, CustomerReturnPermission::View, $record)
                ? ['url' => CustomerReturnResource::getUrl('view', ['record' => $record]), 'available' => true]
                : ['url' => null, 'available' => false];
        }

        if (($data['target_type'] ?? null) !== 'task') {
            return ['url' => null, 'available' => false];
        }

        $task = Task::query()->find((int) $data['target_id']);
        if (! $task instanceof Task || ! $this->taskAuthorization->allows($user, TaskPermission::View, $task)) {
            return ['url' => null, 'available' => false];
        }

        return ['url' => TaskResource::getUrl('view', ['record' => $task]), 'available' => true];
    }

    public function latestImportantUnreadId(User $user): ?string
    {
        return $user->unreadNotifications()
            ->whereIn('type', [
                'task.assigned', 'task.reassigned', 'task.returned', 'task.awaiting_confirmation', 'task.overdue',
                'warranty.sla_due_soon', 'warranty.sla_overdue', 'inventory.low_stock', 'inventory.out_of_stock',
                'claim.needs_filing', 'return.awaiting_qc', 'leave.submitted', 'leave.approved', 'leave.rejected',
                'warning.issued', 'notice.published', 'chat.mention', 'chat.direct_message',
            ])
            ->latest('created_at')
            ->value('id');
    }
}
