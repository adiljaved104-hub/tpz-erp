<?php

namespace App\DTOs\Responsibilities;

use App\Enums\MarketplaceReturnHandlingMode;

readonly class CreateMarketplacePlatformData
{
    public function __construct(
        public string $name,
        public string $code,
        public ?MarketplaceReturnHandlingMode $returnHandlingMode = null,
        public ?int $defaultReturnReceivingWarehouseId = null,
        public bool $customerReturnClaimsEnabled = false,
        public ?string $claimProgramName = null,
    ) {}
}
