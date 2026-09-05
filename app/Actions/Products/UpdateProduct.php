<?php

namespace App\Actions\Products;

use App\DTOs\Products\UpdateProductData;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
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
use Illuminate\Validation\ValidationException;

class UpdateProduct
{
    public function __construct(
        private readonly ProductAuthorization $authorization,
        private readonly ActivityLogger $activity,
        private readonly ProductDuplicateGuard $duplicates,
    ) {}

    public function handle(Product $product, UpdateProductData $data, User $actor): Product
    {
        $this->authorization->authorize($actor, ProductPermission::Update, $product);
        $this->authorizeFinancialFields($data, $actor, $product);
        $validated = Validator::make($this->attributes($data), $this->rules($data))->validate();
        $duplicateCandidates = $this->duplicates->validateContinuation($actor, [
            ...$validated,
            'duplicate_override_reason' => $data->duplicateOverrideReason,
        ], $product->id);

        return DB::transaction(function () use ($product, $data, $validated, $actor, $duplicateCandidates): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
            $brand = ProductBrand::query()->lockForUpdate()->findOrFail($validated['brand_id']);
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($validated['category_id']);

            if ((! $brand->status && $brand->id !== $product->brand_id)
                || (! $category->status && $category->id !== $product->category_id)) {
                throw ValidationException::withMessages(['brand_id' => 'Only active catalog values may be newly assigned.']);
            }

            $attributes = collect($validated)->except(['cost_price', 'selling_price'])->all();
            $attributes['brand'] = $brand->name;
            $attributes['category'] = $category->name;

            if ($data->costPriceProvided) {
                $attributes['cost_price'] = $validated['cost_price'];
            }

            if ($data->sellingPriceProvided) {
                $attributes['selling_price'] = $validated['selling_price'];
            }

            $product->forceFill($attributes);
            $changed = array_keys($product->getDirty());
            $product->save();

            $financial = array_values(array_intersect($changed, ['cost_price', 'selling_price']));
            $operational = array_values(array_diff($changed, ['cost_price', 'selling_price']));

            if ($operational !== []) {
                $this->activity->log('product.updated', $actor, $product, ['changed_fields' => $operational]);
            }

            if ($financial !== []) {
                $this->activity->log('product.financial_fields_changed', $actor, $product, ['changed_fields' => $financial]);
            }

            if ($duplicateCandidates->isNotEmpty()) {
                $this->activity->log('product.duplicate_warning_overridden', $actor, $product, [
                    'candidate_product_ids' => $duplicateCandidates->pluck('productId')->all(),
                    'classifications' => $duplicateCandidates->pluck('classification')->map->value->all(),
                    'reason' => trim((string) $data->duplicateOverrideReason),
                ]);
            }

            return $product;
        });
    }

    private function authorizeFinancialFields(UpdateProductData $data, User $actor, Product $product): void
    {
        if ($data->costPriceProvided) {
            $this->authorization->authorize($actor, ProductPermission::EditCostPrice, $product);
        }

        if ($data->sellingPriceProvided) {
            $this->authorization->authorize($actor, ProductPermission::EditSellingPrice, $product);
        }
    }

    /** @return array<string, mixed> */
    private function attributes(UpdateProductData $data): array
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
    private function rules(UpdateProductData $data): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', Rule::exists('product_brands', 'id')],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')],
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
