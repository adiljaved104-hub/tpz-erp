<?php

namespace App\DTOs\Products;

use App\Enums\ProductCondition;

final readonly class CreateProductData
{
    public function __construct(
        public string $name,
        public int $brandId,
        public int $categoryId,
        public ProductCondition|string $condition = ProductCondition::New,
        public ?string $model = null,
        public ?string $processor = null,
        public ?string $processorClass = null,
        public ?string $processorModel = null,
        public ?string $processorGeneration = null,
        public ?string $ram = null,
        public ?string $storage = null,
        public ?string $screenSize = null,
        public ?string $graphics = null,
        public ?string $color = null,
        public ?bool $touchScreen = null,
        public ?bool $isConvertible360 = null,
        public ?string $accountingTitleOverride = null,
        public ?string $websiteTitleOverride = null,
        public mixed $warranty = 12,
        public ?string $costPrice = null,
        public bool $costPriceProvided = false,
        public ?string $sellingPrice = null,
        public bool $sellingPriceProvided = false,
        public ?string $description = null,
        public ?string $duplicateOverrideReason = null,
    ) {}
}
