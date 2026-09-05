<?php

namespace App\DTOs\Catalog;

final readonly class RenameCatalogItemData
{
    public function __construct(public string $name) {}
}
