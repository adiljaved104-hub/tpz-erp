<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PurchaseItem> */
class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    public function definition(): array
    {
        return ['purchase_id' => Purchase::factory(), 'product_id' => Product::factory(), 'ordered_quantity' => 1,
            'received_quantity' => 0, 'rejected_quantity' => 0, 'unit_cost' => '100.0000', 'line_discount_total' => '0.00',
            'inventory_unit_cost' => '100.0000', 'vat_rate' => '5.00', 'vat_amount' => '5.00', 'line_subtotal' => '100.00',
            'line_net' => '100.00', 'line_total' => '105.00'];
    }
}
