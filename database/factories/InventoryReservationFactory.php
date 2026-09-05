<?php

namespace Database\Factories;

use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InventoryReservation> */
class InventoryReservationFactory extends Factory
{
    protected $model = InventoryReservation::class;

    public function definition(): array
    {
        return [
            'reference' => 'RSV-'.$this->faker->unique()->numerify('######'),
            'reservation_kind' => InventoryReservationKind::BaseProduct,
            'reservation_key' => fn (): string => 'factory:'.Str::uuid(),
            'product_inventory_id' => ProductInventory::factory(),
            'product_id' => fn (array $attributes): int => ProductInventory::query()->findOrFail($attributes['product_inventory_id'])->product_id,
            'warehouse_id' => fn (array $attributes): int => ProductInventory::query()->findOrFail($attributes['product_inventory_id'])->warehouse_id,
            'quantity' => 1,
            'status' => InventoryReservationStatus::Active,
            'reason' => 'Test reservation',
            'idempotency_key' => (string) Str::uuid(),
            'reserved_by_user_id' => User::factory(),
            'reserved_at' => now(),
        ];
    }
}
