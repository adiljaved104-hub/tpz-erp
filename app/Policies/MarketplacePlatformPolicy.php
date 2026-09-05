<?php

namespace App\Policies;

use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;

class MarketplacePlatformPolicy
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ResponsibilityPermission::ManagePlatforms);
    }

    public function view(User $user, MarketplacePlatform $platform): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, MarketplacePlatform $platform): bool
    {
        return $this->viewAny($user);
    }

    public function changeStatus(User $user, MarketplacePlatform $platform): bool
    {
        return $this->viewAny($user);
    }

    public function changeCode(User $user, MarketplacePlatform $platform): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, MarketplacePlatform $platform): bool
    {
        return false;
    }

    public function restore(User $user, MarketplacePlatform $platform): bool
    {
        return false;
    }

    public function forceDelete(User $user, MarketplacePlatform $platform): bool
    {
        return false;
    }
}
