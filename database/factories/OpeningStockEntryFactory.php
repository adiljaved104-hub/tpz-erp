<?php

namespace Database\Factories;

use App\Models\OpeningStockEntry;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OpeningStockEntry> */
class OpeningStockEntryFactory extends Factory
{
    protected $model = OpeningStockEntry::class;

    public function definition(): array
    {
        return [
            'reference' => 'OS-'.$this->faker->unique()->numerify('######'),
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'available_quantity' => 1,
            'damaged_quantity' => 0,
            'unit_cost' => '1.0000',
            'reason' => 'Test opening stock',
            'idempotency_key' => (string) Str::uuid(),
            'movement_group' => (string) Str::uuid(),
            'posted_by_user_id' => User::factory(),
            'posted_at' => now(),
        ];
    }
}
