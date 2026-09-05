<?php

namespace App\Actions\Products;

use App\DTOs\Products\CreateProductData;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ProductIntelligence\ProductDuplicateGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateProduct
{
    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly GenerateProductSku $sku,
        private readonly ActivityLogger $activity,
        private readonly ProductDuplicateGuard $duplicates,
    ) {}

    public function handle(CreateProductData $data, User $actor): Product
    {
        $this->authorization->authorize($actor, ProductPermission::Create);
        $this->authorizeFinancialFields($data, $actor);
        $validated = Validator::make($this->attributes($data), $this->rules($data))->validate();
        $duplicateCandidates = $this->duplicates->validateContinuation($actor, [
            ...$validated,
            'duplicate_override_reason' => $data->duplicateOverrideReason,
        ]);
        $sku = $this->sku->handle($actor);

        return DB::transaction(function () use ($validated, $sku, $data, $actor, $duplicateCandidates): Product {
            $brand = ProductBrand::query()->lockForUpdate()->whereKey($validated['brand_id'])->where('status', true)->firstOrFail();
            $category = ProductCategory::query()->lockForUpdate()->whereKey($validated['category_id'])->where('status', true)->firstOrFail();
            $product = new Product;
            $product->forceFill([
                ...$validated,
                'brand' => $brand->name,
                'category' => $category->name,
                'sku' => $sku,
                'cost_price' => $data->costPriceProvided ? $validated['cost_price'] : null,
                'selling_price' => $data->sellingPriceProvided ? $validated['selling_price'] : '0.00',
                'status' => ProductStatus::Active,
            ])->save();

            $this->activity->log('product.sku_assigned', $actor, $product);
            $this->activity->log('product.created', $actor, $product);

            if ($duplicateCandidates->isNotEmpty()) {
                $this->activity->log('product.duplicate_warning_overridden', $actor, $product, [
                    'candidate_product_ids' => $duplicateCandidates->pluck('productId')->all(),
                    'classifications' => $duplicateCandidates->pluck('classification')->map->value->all(),
                    'reason' => trim((string) $data->duplicateOverrideReason),
                ]);
            }

            if ($data->costPriceProvided || $data->sellingPriceProvided) {
                $this->activity->log('product.financial_fields_changed', $actor, $product, [
                    'changed_fields' => array_values(array_filter([
                        $data->costPriceProvided ? 'cost_price' : null,
                        $data->sellingPriceProvided ? 'selling_price' : null,
                    ])),
                ]);
            }

            return $product;
        });
    }

    private function authorizeFinancialFields(CreateProductData $data, User $actor): void
    {
        if ($data->costPriceProvided) {
            $this->authorization->authorize($actor, ProductPermission::EditCostPrice);
        }

        if ($data->sellingPriceProvided) {
            $this->authorization->authorize($actor, ProductPermission::EditSellingPrice);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(CreateProductData $data): array
    {
        return [
            'name' => trim($data->name),
            'brand_id' => $data->brandId,
            'category_id' => $data->categoryId,
            'condition' => $data->condition instanceof ProductCondition ? $data->condition->value : $data->condition,
            'model' => $this->nullable($data->model),
            'processor' => $this->nullable($data->processor),
            'ram' => $this->nullable($data->ram),
            'storage' => $this->nullable($data->storage),
            'screen_size' => $this->nullable($data->screenSize),
            'graphics' => $this->nullable($data->graphics),
            'color' => $this->nullable($data->color),
            'warranty' => $data->warranty,
            'cost_price' => $data->costPrice,
            'selling_price' => $data->sellingPrice,
            'description' => $this->nullable($data->description),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(CreateProductData $data): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', Rule::exists('product_brands', 'id')->where('status', true)],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('status', true)],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'model' => ['nullable', 'string', 'max:255'],
            'processor' => ['nullable', 'string', 'max:255'],
            'ram' => ['nullable', 'string', 'max:255'],
            'storage' => ['nullable', 'string', 'max:255'],
            'screen_size' => ['nullable', 'string', 'max:255'],
            'graphics' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'warranty' => ['required', 'integer', 'min:0', 'max:600'],
            'cost_price' => $data->costPriceProvided
                ? ['nullable', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/']
                : ['nullable'],
            'selling_price' => $data->sellingPriceProvided
                ? ['required', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/']
                : ['nullable'],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
