<?php

namespace App\Services\Authorization;

use App\Contracts\StockTransferPermissionResolver;
use App\Enums\StockTransferPermission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransfers\StockTransferResponsibilityScopeService;
use Illuminate\Auth\Access\AuthorizationException;

class StockTransferAuthorization
{
    public function __construct(private readonly StockTransferPermissionResolver $resolver, private readonly StockTransferResponsibilityScopeService $scope) {}

    public function allows(User $user, StockTransferPermission $permission, ?StockTransfer $transfer = null): bool
    {
        return $this->resolver->allows($user, $permission)
            && ($transfer === null || $this->scope->canAccessTransfer($user, $transfer));
    }

    public function authorize(User $user, StockTransferPermission $permission, ?StockTransfer $transfer = null): void
    {
        if (! $this->allows($user, $permission, $transfer)) {
            throw new AuthorizationException;
        }
    }
}
