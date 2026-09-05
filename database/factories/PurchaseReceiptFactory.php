<?php

namespace Database\Factories;

use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PurchaseReceipt> */
class PurchaseReceiptFactory extends Factory
{
    protected $model = PurchaseReceipt::class;

    public function definition(): array
    {
        $purchase = Purchase::factory();

        return ['reference' => 'GRN-'.now()->year.'-'.fake()->unique()->numerify('######'), 'purchase_id' => $purchase,
            'warehouse_id' => fn (array $attributes) => Purchase::query()->find($attributes['purchase_id'])?->warehouse_id,
            'received_at' => now(), 'received_by_user_id' => User::factory(), 'idempotency_key' => (string) Str::uuid(),
            'movement_group' => (string) Str::uuid(), 'created_at' => now()];
    }
}
