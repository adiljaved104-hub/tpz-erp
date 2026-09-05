<?php

namespace App\DTOs\Responsibilities;

readonly class ChangeMarketplacePlatformStatusData
{
    public function __construct(public bool $active, public string $reason) {}
}
