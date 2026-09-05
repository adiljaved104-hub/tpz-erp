<?php

namespace App\Services\ProductIntelligence;

use App\Contracts\ProductQueryInterpreterInterface;
use App\DTOs\ProductIntelligence\ParsedProductQuery;

class LocalProductQueryInterpreter implements ProductQueryInterpreterInterface
{
    public function __construct(private readonly ProductQueryParser $parser) {}

    public function interpret(string $query, array $attributes = [], array $knownBrands = []): ParsedProductQuery
    {
        return $this->parser->parse($query, $attributes, $knownBrands);
    }
}
