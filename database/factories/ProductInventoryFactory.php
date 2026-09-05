<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductInventory> */
class ProductInventoryFactory extends Factory
{
    protected $model = ProductInventory::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'available_quantity' => 0,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => null,
        ];
    }
}
