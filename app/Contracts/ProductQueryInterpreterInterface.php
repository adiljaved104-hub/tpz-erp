<?php

namespace App\Contracts;

use App\DTOs\ProductIntelligence\ParsedProductQuery;

interface ProductQueryInterpreterInterface
{
    /** @param array<string, mixed> $attributes @param list<string> $knownBrands */
    public function interpret(string $query, array $attributes = [], array $knownBrands = []): ParsedProductQuery;
}
