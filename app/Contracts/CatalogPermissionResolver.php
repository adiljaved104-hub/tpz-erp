<?php

namespace App\Contracts;

use App\Enums\CatalogPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface CatalogPermissionResolver
{
    public function allows(User $user, CatalogPermission $permission, ?Model $record = null): bool;
}
