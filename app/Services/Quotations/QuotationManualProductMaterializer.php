<?php

namespace App\Services\Quotations;

use App\Enums\InventoryItemType;
use App\Enums\ProductStatus;
use App\Enums\QuotationItemSourceType;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationManualProductMaterializer
{
    public function __construct(
        private readonly QuotationSourcingService $sourcing,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, string>  $preallocatedSkus  Quotation item ID => SKU
     * @return Collection<int, Product>
     */
    public function materialize(Quotation $quotation, User $actor, array $preallocatedSkus): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Manual quotation Products must be materialized inside the conversion transaction.');
        }

        $manualItems = $quotation->items->filter(
            fn (QuotationItem $item): bool => $item->source_type === QuotationItemSourceType::ManualSourced
        );
        if ($manualItems->isEmpty()) {
            return collect();
        }

        $this->sourcing->authorizeManage($actor, $quotation);
        if ($this->responsibilities->requiresScope($actor)) {
            throw ValidationException::withMessages([
                'items' => 'Manual sourced Products cannot be materialized by a Responsibility-scoped user.',
            ]);
        }

        $products = collect();
        foreach ($manualItems as $item) {
            if ($item->materialized_product_id !== null) {
                $products->put($item->id, Product::query()->products()->lockForUpdate()->findOrFail($item->materialized_product_id));

                continue;
            }

            $sku = $preallocatedSkus[$item->id] ?? null;
            if (! is_string($sku) || ! preg_match('/^TPZ-\d{6,}$/', $sku)) {
                throw new \LogicException('A valid Product SKU must be reserved before conversion.');
            }

            $brand = ProductBrand::query()->active()->lockForUpdate()->find($item->manual_brand_id);
            $category = ProductCategory::query()->active()->lockForUpdate()->find($item->manual_category_id);
            if (! $brand || ! $category) {
                throw ValidationException::withMessages([
                    'items' => 'A manual Product Brand or Category is no longer active.',
                ]);
            }

            $product = new Product;
            $product->forceFill([
                'sku' => $sku,
                'inventory_item_type' => InventoryItemType::Product,
                'name' => $item->product_name,
                'brand' => $brand->name,
                'brand_id' => $brand->id,
                'category' => $category->name,
                'category_id' => $category->id,
                'model' => $item->model_name,
                'condition' => $item->manual_condition,
                'warranty' => 0,
                'cost_price' => null,
                'selling_price' => $item->unit_price_including_vat,
                'description' => $item->description,
                'status' => ProductStatus::Active,
            ])->save();

            $item->forceFill([
                'materialized_product_id' => $product->id,
                'materialized_by_user_id' => $actor->id,
                'materialized_at' => now(),
            ])->save();

            $this->activity->log('product.sku_assigned', $actor, $product);
            $this->activity->log('product.created', $actor, $product, [
                'source' => 'quotation_manual_sourcing',
                'quotation_id' => $quotation->id,
                'quotation_item_id' => $item->id,
            ]);
            $this->activity->log('quotation.manual_product_materialized', $actor, $item, [
                'quotation_reference' => $quotation->reference,
                'product_id' => $product->id,
                'sku' => $product->sku,
            ]);
            $products->put($item->id, $product);
        }

        return $products;
    }
}
