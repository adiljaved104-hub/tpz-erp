<?php

namespace App\DTOs\Responsibilities;

final readonly class ChangeMarketplacePlatformCodeData
{
    public function __construct(
        public string $newCode,
        public string $reason,
    ) {}
}
