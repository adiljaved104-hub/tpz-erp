<?php

namespace App\Services\Authorization;

use App\Contracts\PurchasePermissionResolver;
use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class PurchaseAuthorization
{
    public function __construct(private readonly PurchasePermissionResolver $resolver) {}

    public function allows(User $user, PurchasePermission $permission, ?Purchase $purchase = null): bool
    {
        return $user->employee?->status === true && $this->resolver->allows($user, $permission, $purchase);
    }

    public function authorize(User $user, PurchasePermission $permission, ?Purchase $purchase = null): void
    {
        if (! $this->allows($user, $permission, $purchase)) {
            throw new AuthorizationException;
        }
    }
}
