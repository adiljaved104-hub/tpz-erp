<?php

namespace App\Services\Authorization;

use App\Contracts\PeoplePermissionResolver;
use App\Enums\PeoplePermission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class PeopleAuthorization
{
    public function __construct(private readonly PeoplePermissionResolver $resolver) {}

    public function allows(User $user, PeoplePermission $permission): bool
    {
        return $this->resolver->allows($user, $permission);
    }

    public function authorize(User $user, PeoplePermission $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException;
        }
    }
}
