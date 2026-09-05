<?php

namespace App\Actions\Products;

use App\DTOs\Products\ProductExportData;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Products\ProductExportService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportProducts
{
    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ProductExportService $exports,
    ) {}

    public function handle(ProductExportData $data, User $actor): StreamedResponse
    {
        $this->authorization->authorize($actor, ProductPermission::Export);

        $validated = Validator::make($data->filters(), [
            'search' => ['sometimes', 'string', 'max:255'],
            'brand_id' => ['sometimes', 'integer', Rule::exists('product_brands', 'id')],
            'category_id' => ['sometimes', 'integer', Rule::exists('product_categories', 'id')],
            'condition' => ['sometimes', Rule::enum(ProductCondition::class)],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
        ])->validate();

        return $this->exports->stream(new ProductExportData(
            search: $validated['search'] ?? null,
            brandId: $validated['brand_id'] ?? null,
            categoryId: $validated['category_id'] ?? null,
            condition: $validated['condition'] ?? null,
            status: $validated['status'] ?? null,
        ), $actor);
    }
}
