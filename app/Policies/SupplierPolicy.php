<?php

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;

class SupplierPolicy
{
    public function __construct(private readonly PurchaseAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::SupplierView);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->canManage($user);
    }

    public function changeStatus(User $user, Supplier $supplier): bool
    {
        return $this->canManage($user);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function restore(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function forceDelete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    private function canManage(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::SupplierManage);
    }
}
