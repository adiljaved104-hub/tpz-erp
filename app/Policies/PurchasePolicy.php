<?php

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;

class PurchasePolicy
{
    public function __construct(private readonly PurchaseAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::View);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::View, $purchase);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::Create);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::UpdateDraft, $purchase);
    }

    public function approve(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::Approve, $purchase);
    }

    public function cancel(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::Cancel, $purchase);
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::Receive, $purchase);
    }

    public function close(User $user, Purchase $purchase): bool
    {
        return $this->authorization->allows($user, PurchasePermission::Close, $purchase);
    }

    public function quickReceive(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::QuickReceive);
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }

    public function restore(User $user, Purchase $purchase): bool
    {
        return false;
    }

    public function forceDelete(User $user, Purchase $purchase): bool
    {
        return false;
    }
}
