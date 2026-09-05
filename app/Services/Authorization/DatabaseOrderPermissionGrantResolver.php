<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Contracts\OrderPermissionGrantResolver;
use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;

class DatabaseOrderPermissionGrantResolver implements OrderPermissionGrantResolver
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function decision(User $user, OrderPermission $permission, ?Order $order = null): ?bool
    {
        return $this->overrides->decision($user, $permission->value);
    }
}
