<?php

namespace Database\Factories;

use App\Enums\InventoryLocationType;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Warehouse> */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company().' Warehouse',
            'code' => strtoupper($this->faker->unique()->bothify('WH-##??')),
            'address' => $this->faker->address(),
            'status' => true,
            'is_default' => false,
            'location_type' => InventoryLocationType::CompanyWarehouse,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => false]);
    }
}
