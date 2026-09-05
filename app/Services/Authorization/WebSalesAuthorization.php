<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class WebSalesAuthorization
{
    public function __construct(
        private readonly EmployeePermissionOverrideResolver $overrides,
        private readonly OrderAuthorization $orders,
    ) {}

    public function roleDefault(User $user, WebSalesPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => ! in_array($permission, [WebSalesPermission::ViewCost, WebSalesPermission::ViewGrossProfit], true),
            EmployeeRole::Manager, EmployeeRole::Staff => in_array($permission, [
                WebSalesPermission::View,
                WebSalesPermission::Create,
                WebSalesPermission::Update,
                WebSalesPermission::ViewRevenue,
                WebSalesPermission::Cancel,
            ], true),
            default => false,
        };
    }

    public function allows(User $user, WebSalesPermission $permission, ?Order $order = null): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);
        $allowed = $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);

        if (! $allowed || ($order !== null && $order->web_sales_channel === null)) {
            return false;
        }

        return $this->orders->allows($user, $this->matchingOrderPermission($permission), $order);
    }

    public function authorize(User $user, WebSalesPermission $permission, ?Order $order = null): void
    {
        throw_unless($this->allows($user, $permission, $order), AuthorizationException::class);
    }

    private function matchingOrderPermission(WebSalesPermission $permission): OrderPermission
    {
        return match ($permission) {
            WebSalesPermission::View, WebSalesPermission::ViewAll => OrderPermission::View,
            WebSalesPermission::Create => OrderPermission::Create,
            WebSalesPermission::Update => OrderPermission::UpdateDraft,
            WebSalesPermission::ViewRevenue => OrderPermission::ViewSellingPrice,
            WebSalesPermission::ViewCost => OrderPermission::ViewCost,
            WebSalesPermission::ViewGrossProfit => OrderPermission::ViewProfit,
            WebSalesPermission::Cancel => OrderPermission::Cancel,
        };
    }
}
