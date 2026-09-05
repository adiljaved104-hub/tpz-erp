<?php

namespace Database\Factories;

use App\Models\ProductBrand;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductBrand> */
class ProductBrandFactory extends Factory
{
    protected $model = ProductBrand::class;

    public function definition(): array
    {
        $name = 'Brand '.fake()->unique()->numerify('######');

        return ['name' => $name, 'normalized_name' => strtolower($name), 'status' => true, 'created_by_user_id' => null];
    }
}
