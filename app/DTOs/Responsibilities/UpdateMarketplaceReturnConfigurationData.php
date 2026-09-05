<?php

namespace App\DTOs\Responsibilities;

use App\Enums\MarketplaceReturnHandlingMode;

readonly class UpdateMarketplaceReturnConfigurationData
{
    public function __construct(
        public ?MarketplaceReturnHandlingMode $returnHandlingMode,
        public ?int $defaultReturnReceivingWarehouseId,
        public bool $customerReturnClaimsEnabled = false,
        public ?string $claimProgramName = null,
    ) {}
}
