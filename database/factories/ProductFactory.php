<?php

namespace Database\Factories;

use App\Enums\InventoryItemType;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'TEST-'.fake()->unique()->numerify('######'),
            'inventory_item_type' => InventoryItemType::Product,
            'name' => fake()->words(3, true),
            'brand' => 'HP',
            'brand_id' => fn (): int => ProductBrand::query()->firstOrCreate(
                ['normalized_name' => 'hp'],
                ['name' => 'HP', 'status' => true, 'created_by_user_id' => null],
            )->id,
            'category' => 'Laptop',
            'category_id' => fn (): int => ProductCategory::query()->firstOrCreate(
                ['normalized_name' => 'laptop'],
                ['name' => 'Laptop', 'status' => true, 'created_by_user_id' => null],
            )->id,
            'condition' => ProductCondition::New,
            'warranty' => 12,
            'cost_price' => null,
            'selling_price' => '0.00',
            'status' => ProductStatus::Active,
        ];
    }
}
