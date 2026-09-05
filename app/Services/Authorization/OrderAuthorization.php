<?php

namespace App\Services\Authorization;

use App\Contracts\OrderPermissionResolver;
use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;

class OrderAuthorization
{
    public function __construct(
        private readonly OrderPermissionResolver $resolver,
        private readonly OrderResponsibilityScopeService $responsibilities,
    ) {}

    public function allows(User $user, OrderPermission $permission, ?Order $order = null): bool
    {
        if ($user->employee?->status !== true || ! $this->resolver->allows($user, $permission, $order)) {
            return false;
        }

        return $order === null || $this->responsibilities->canAccessOrder($user, $order);
    }

    public function authorize(User $user, OrderPermission $permission, ?Order $order = null): void
    {
        if (! $this->allows($user, $permission, $order)) {
            throw new AuthorizationException;
        }
    }
}
