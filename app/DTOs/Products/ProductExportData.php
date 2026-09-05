<?php

namespace App\DTOs\Products;

final readonly class ProductExportData
{
    public function __construct(
        public ?string $search = null,
        public ?int $brandId = null,
        public ?int $categoryId = null,
        public ?string $condition = null,
        public ?string $status = null,
    ) {}

    /** @return array<string, string|int> */
    public function filters(): array
    {
        return array_filter([
            'search' => $this->search === null ? null : trim($this->search),
            'brand_id' => $this->brandId,
            'category_id' => $this->categoryId,
            'condition' => $this->condition,
            'status' => $this->status,
        ], fn (mixed $value): bool => filled($value));
    }
}
