<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceConnectionCapability;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use Illuminate\Support\Collection;

class MarketplaceConnectionCapabilityResolver
{
    /** @return Collection<int, MarketplaceConnection> */
    public function connections(MarketplaceAccount $account, MarketplaceConnectionCapability $capability): Collection
    {
        return $account->connections()->where('enabled', true)
            ->whereHas('capabilities', fn ($query) => $query->where('capability', $capability->value)->where('enabled', true))
            ->orderBy('priority')->orderBy('id')->get();
    }
}
