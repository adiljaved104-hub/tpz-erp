<?php

namespace App\Contracts;

use App\Enums\StockTransferPermission;
use App\Models\User;

interface StockTransferPermissionResolver
{
    public function allows(User $user, StockTransferPermission $permission): bool;
}
