<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductCategory> */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        $name = 'Category '.fake()->unique()->numerify('######');

        return ['name' => $name, 'normalized_name' => strtolower($name), 'status' => true, 'created_by_user_id' => null];
    }
}
