<?php

namespace App\DTOs\Catalog;

final readonly class CreateCatalogItemData
{
    public function __construct(public string $name) {}
}
