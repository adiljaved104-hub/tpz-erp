<?php

namespace Database\Factories;

use App\Models\MarketplacePlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketplacePlatform> */
class MarketplacePlatformFactory extends Factory
{
    protected $model = MarketplacePlatform::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));

        return [
            'name' => $name,
            'normalized_name' => $normalized,
            'code' => fake()->unique()->slug(2),
            'status' => true,
            'created_by_user_id' => null,
        ];
    }
}
