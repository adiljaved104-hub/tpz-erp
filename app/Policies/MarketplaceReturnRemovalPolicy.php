<?php

namespace App\Policies;

use App\Enums\MarketplaceReturnPermission;
use App\Models\MarketplaceReturnRemoval;
use App\Models\User;
use App\Services\Authorization\MarketplaceReturnAuthorization;

class MarketplaceReturnRemovalPolicy
{
    public function __construct(private readonly MarketplaceReturnAuthorization $a) {}

    public function viewAny(User $u): bool
    {
        return $this->a->allows($u, MarketplaceReturnPermission::View);
    }

    public function view(User $u, MarketplaceReturnRemoval $r): bool
    {
        return $this->a->allows($u, MarketplaceReturnPermission::View, $r);
    }

    public function create(User $u): bool
    {
        return $this->a->allows($u, MarketplaceReturnPermission::RequestRemoval);
    }

    public function delete(User $u, MarketplaceReturnRemoval $r): bool
    {
        return false;
    }
}
