<?php

namespace App\Services\Authorization;

use App\Contracts\CatalogPermissionResolver;
use App\Enums\CatalogPermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CatalogAuthorization
{
    public function __construct(private readonly CatalogPermissionResolver $resolver) {}

    public function allows(User $user, CatalogPermission $permission, ?Model $record = null): bool
    {
        return $this->resolver->allows($user, $permission, $record);
    }

    public function authorize(User $user, CatalogPermission $permission, ?Model $record = null): void
    {
        if (! $this->allows($user, $permission, $record)) {
            throw new AuthorizationException;
        }
    }
}
