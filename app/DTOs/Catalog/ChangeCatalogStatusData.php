<?php

namespace App\DTOs\Catalog;

final readonly class ChangeCatalogStatusData
{
    public function __construct(public bool $active, public string $reason) {}
}
