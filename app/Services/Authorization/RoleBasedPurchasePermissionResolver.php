<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\PurchasePermissionResolver;
use App\Enums\EmployeeRole;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;

class RoleBasedPurchasePermissionResolver implements PurchasePermissionResolver
{
    public function __construct(private readonly ?EmployeePermissionOverrideResolver $overrides = null) {}

    public function allows(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool
    {
        $roleDefault = $this->roleDefault($user, $permission, $purchase);

        if ($user->employee?->role === EmployeeRole::Owner) {
            return $roleDefault;
        }

        return $this->overrides?->decision($user, $permission->value) ?? $roleDefault;
    }

    public function roleDefault(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner => true,
            EmployeeRole::Admin => in_array($permission, [
                PurchasePermission::View, PurchasePermission::Create, PurchasePermission::UpdateDraft,
                PurchasePermission::ViewFinancials, PurchasePermission::ViewCostHistory,
                PurchasePermission::Cancel, PurchasePermission::Receive, PurchasePermission::ViewReceipts,
                PurchasePermission::Export, PurchasePermission::QuickReceive,
                PurchasePermission::SupplierView, PurchasePermission::SupplierManage,
            ], true),
            EmployeeRole::Manager => $this->managerAllows($user, $permission, $purchase),
            EmployeeRole::Staff => false,
            default => false,
        };
    }

    private function managerAllows(User $user, PurchasePermission $permission, ?Purchase $purchase): bool
    {
        if (in_array($permission, [PurchasePermission::View, PurchasePermission::Create, PurchasePermission::ViewReceipts, PurchasePermission::ViewCostHistory, PurchasePermission::Export, PurchasePermission::SupplierView], true)) {
            return true;
        }

        $ownedDraft = $purchase !== null
            && $purchase->status === PurchaseStatus::Draft
            && $purchase->created_by_user_id === $user->id;

        return $ownedDraft && in_array($permission, [
            PurchasePermission::UpdateDraft, PurchasePermission::ViewFinancials,
            PurchasePermission::Cancel,
        ], true);
    }
}
