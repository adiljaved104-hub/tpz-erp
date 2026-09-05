<?php

namespace App\Services\Authorization;

use App\Contracts\OrderPermissionGrantResolver;
use App\Contracts\OrderPermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

class RoleBasedOrderPermissionResolver implements OrderPermissionResolver
{
    public function __construct(private readonly OrderPermissionGrantResolver $grants) {}

    public function allows(User $user, OrderPermission $permission, ?Order $order = null): bool
    {
        $roleDefault = $this->roleDefault($user, $permission, $order);

        if ($user->employee?->role === EmployeeRole::Owner) {
            return $roleDefault;
        }

        return $this->grants->decision($user, $permission, $order) ?? $roleDefault;
    }

    public function roleDefault(User $user, OrderPermission $permission, ?Order $order = null): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => ! in_array($permission, [OrderPermission::ViewCost, OrderPermission::ViewProfit], true),
            EmployeeRole::Manager => $this->managerAllows($user, $permission, $order),
            EmployeeRole::Staff => $this->staffAllows($user, $permission, $order),
            default => false,
        };
    }

    private function managerAllows(User $user, OrderPermission $permission, ?Order $order): bool
    {
        if ($permission === OrderPermission::Fulfill) {
            return false;
        }

        if (in_array($permission, [
            OrderPermission::View, OrderPermission::Create, OrderPermission::UpdateDraft,
            OrderPermission::Confirm, OrderPermission::Reserve, OrderPermission::Process,
            OrderPermission::ViewSellingPrice, OrderPermission::EditSellingPrice, OrderPermission::Export,
        ], true)) {
            return true;
        }

        return $permission === OrderPermission::Cancel && $this->ownsOpenOrder($user, $order);
    }

    private function staffAllows(User $user, OrderPermission $permission, ?Order $order): bool
    {
        if ($permission === OrderPermission::Fulfill) {
            return false;
        }

        if (in_array($permission, [
            OrderPermission::View, OrderPermission::Create, OrderPermission::UpdateDraft,
            OrderPermission::Reserve, OrderPermission::ViewSellingPrice, OrderPermission::EditSellingPrice,
        ], true)) {
            return true;
        }

        return $permission === OrderPermission::Cancel && $this->ownsOpenOrder($user, $order);
    }

    private function ownsOpenOrder(User $user, ?Order $order): bool
    {
        return $order !== null
            && $order->created_by_user_id === $user->id
            && in_array($order->status, [OrderStatus::Draft, OrderStatus::Reserved], true);
    }
}
