<?php

namespace App\DTOs\Orders;

final readonly class OrderUpgradePlan
{
    /**
     * @param  array<string, mixed>  $configurationSnapshot
     * @param  array<string, mixed>  $recipeSnapshot
     * @param  array<string, mixed>  $recoverySnapshot
     * @param  list<array<string, mixed>>  $lines
     */
    public function __construct(
        public int $productId,
        public int $salesConfigurationId,
        public int $upgradeRecipeId,
        public int $hardwareProfileVersion,
        public array $configurationSnapshot,
        public array $recipeSnapshot,
        public string $suggestedSellingAddon,
        public string $labourUnitCost,
        public array $recoverySnapshot,
        public array $lines,
    ) {}

    /** @return list<array<string, mixed>> */
    public function installLines(): array
    {
        return array_values(array_filter($this->lines, fn (array $line): bool => $line['operation'] === 'install'));
    }

    /** @return list<array<string, mixed>> */
    public function recoveryLines(): array
    {
        return array_values(array_filter($this->lines, fn (array $line): bool => in_array($line['operation'], ['remove_and_return', 'remove_as_damaged'], true)));
    }
}
