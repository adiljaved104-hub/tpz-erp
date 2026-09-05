<?php

namespace App\Policies;

use App\Enums\PurchasePermission;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;

class PurchaseReceiptPolicy
{
    public function __construct(private readonly PurchaseAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, PurchasePermission::ViewReceipts);
    }

    public function view(User $user, PurchaseReceipt $receipt): bool
    {
        return $this->authorization->allows($user, PurchasePermission::ViewReceipts, $receipt->purchase);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PurchaseReceipt $receipt): bool
    {
        return false;
    }

    public function delete(User $user, PurchaseReceipt $receipt): bool
    {
        return false;
    }

    public function restore(User $user, PurchaseReceipt $receipt): bool
    {
        return false;
    }

    public function forceDelete(User $user, PurchaseReceipt $receipt): bool
    {
        return false;
    }
}
