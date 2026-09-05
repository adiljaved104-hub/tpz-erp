<?php

namespace Database\Factories;

use App\Enums\StockMovementType;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StockMovement> */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'reference' => 'SM-'.$this->faker->unique()->numerify('######'),
            'movement_group' => (string) Str::uuid(),
            'product_inventory_id' => ProductInventory::factory(),
            'product_id' => fn (array $attributes): int => ProductInventory::query()->findOrFail($attributes['product_inventory_id'])->product_id,
            'warehouse_id' => fn (array $attributes): int => ProductInventory::query()->findOrFail($attributes['product_inventory_id'])->warehouse_id,
            'movement_type' => StockMovementType::Reservation,
            'quantity' => 1,
            'available_delta' => 0,
            'reserved_delta' => 1,
            'damaged_delta' => 0,
            'available_before' => 1,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 1,
            'damaged_before' => 0,
            'damaged_after' => 0,
            'actor_user_id' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}
