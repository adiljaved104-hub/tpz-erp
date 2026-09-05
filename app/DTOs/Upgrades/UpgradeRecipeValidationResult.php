<?php

namespace App\DTOs\Upgrades;

final readonly class UpgradeRecipeValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $resultingLayout
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $resultingLayout,
        public string $buildSummary,
    ) {}
}
