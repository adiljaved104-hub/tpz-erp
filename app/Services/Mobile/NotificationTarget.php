<?php

namespace App\Services\Mobile;

use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\CustomerReturn;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOrderEvent;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\SafetClaim;
use App\Models\StockRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Inventory\StockRequestService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;

class NotificationTarget
{
    public function resolve(User $user, array $data): ?array
    {
        $id = $data['target_id'] ?? null;
        if (! is_numeric($id)) {
            return null;
        }
        [$model, $module] = match ($data['target_type'] ?? '') {
            'order' => [Order::class, 'orders'], 'task' => [Task::class, 'tasks'],
            'customer_return' => [CustomerReturn::class, 'returns'], 'warranty_repair' => [WarrantyRepair::class, 'warranty'],
            'safet_claim' => [SafetClaim::class, 'cases/claims'], 'complaint' => [Complaint::class, 'cases/complaints'],
            'conversation' => [Conversation::class, 'chat'], 'responsibility_assignment' => [ResponsibilityAssignment::class, 'responsibilities'],
            'product_inventory' => [ProductInventory::class, 'inventory'], 'stock_request' => [StockRequest::class, 'stock-requests'],
            'marketplace_operation_incident' => [MarketplaceOperationIncident::class, 'marketplace-operations'],
            'marketplace_order_event' => [MarketplaceOrderEvent::class, 'marketplace-operations'],
            'hr_notice' => [HrNotice::class, 'hr/notices'], 'employee_warning' => [EmployeeWarning::class, 'hr/warnings'], default => [null, null],
        };
        if ($model === null || ! ($record = $model::query()->find((int) $id))) {
            return null;
        }
        if ($record instanceof WarrantyRepair && $record->isInternalCompanyOwnedRepair()) {
            $module = 'internal-repairs';
        }

        $allowed = match (true) {
            $record instanceof Order => app(OrderAuthorization::class)->allows($user, OrderPermission::View, $record),
            $record instanceof Task => app(TaskAuthorization::class)->allows($user, TaskPermission::View, $record),
            $record instanceof CustomerReturn => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View, $record),
            $record instanceof SafetClaim => app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View, $record),
            $record instanceof Complaint => app(ComplaintAuthorization::class)->allows($user, ComplaintPermission::View, $record),
            $record instanceof WarrantyRepair => app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View, $record),
            $record instanceof HrNotice => app(HrRecordAuthorization::class)->canViewNotice($user, $record),
            $record instanceof EmployeeWarning => app(HrRecordAuthorization::class)->canViewWarning($user, $record),
            $record instanceof Conversation => app(ChatAuthorization::class)->canAccessConversation($user, $record),
            $record instanceof ResponsibilityAssignment => app(ResponsibilityAuthorization::class)->canView($user, $record),
            $record instanceof ProductInventory => (app(InventoryAuthorization::class)->allows($user, InventoryPermission::View, $record)
                || app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn))
                && app(ResponsibilityProductScopeService::class)->canAccessInventory($user, $record->id),
            $record instanceof StockRequest => app(StockRequestService::class)->canView($user, $record),
            $record instanceof MarketplaceOperationIncident => $record->recipients()->where('user_id', $user->id)->exists()
                || in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true),
            $record instanceof MarketplaceOrderEvent => in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                || $user->notifications()->where('data->target_type', 'marketplace_order_event')->where('data->target_id', $record->id)->exists(),
            default => false,
        };

        return $allowed ? ['module' => $module, 'id' => (int) $id] : null;
    }
}
