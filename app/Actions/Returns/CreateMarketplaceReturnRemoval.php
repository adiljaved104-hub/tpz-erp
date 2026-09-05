<?php

namespace App\Actions\Returns;

use App\DTOs\Returns\CreateMarketplaceReturnRemovalData;
use App\Models\MarketplaceReturnRemoval;
use App\Models\User;
use App\Services\Returns\MarketplaceReturnService;

class CreateMarketplaceReturnRemoval
{
    public function __construct(private readonly MarketplaceReturnService $service) {}

    public function handle(CreateMarketplaceReturnRemovalData $data, User $actor): MarketplaceReturnRemoval
    {
        return $this->service->requestBulkRemoval($data, $actor);
    }
}
