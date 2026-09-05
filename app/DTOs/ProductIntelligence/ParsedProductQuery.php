<?php

namespace App\DTOs\ProductIntelligence;

final readonly class ParsedProductQuery
{
    /** @param list<string> $tokens @param list<string> $unparsedTokens */
    public function __construct(
        public string $raw,
        public string $normalized,
        public array $tokens,
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $cpuFamily = null,
        public ?string $cpuModel = null,
        public ?string $gpuVendor = null,
        public ?string $gpuModel = null,
        public ?int $ramMb = null,
        public ?int $storageGb = null,
        public ?string $storageType = null,
        public ?float $screenSizeInches = null,
        public array $unparsedTokens = [],
        public float $confidence = 0.0,
    ) {}

    /** @return array<string, string|int|float|null> */
    public function attributes(): array
    {
        return [
            'brand' => $this->brand,
            'model' => $this->model,
            'cpu_family' => $this->cpuFamily,
            'cpu_model' => $this->cpuModel,
            'gpu_vendor' => $this->gpuVendor,
            'gpu_model' => $this->gpuModel,
            'ram_mb' => $this->ramMb,
            'storage_gb' => $this->storageGb,
            'storage_type' => $this->storageType,
            'screen_size_inches' => $this->screenSizeInches,
        ];
    }
}
