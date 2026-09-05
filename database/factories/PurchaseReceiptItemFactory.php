<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PurchaseReceiptItem> */
class PurchaseReceiptItemFactory extends Factory
{
    protected $model = PurchaseReceiptItem::class;

    public function definition(): array
    {
        return ['purchase_receipt_id' => PurchaseReceipt::factory(), 'purchase_item_id' => PurchaseItem::factory(),
            'product_id' => Product::factory(), 'quantity_received' => 1, 'accepted_quantity' => 1, 'damaged_quantity' => 0,
            'rejected_quantity' => 0, 'inventory_unit_cost' => '100.0000', 'posting_key' => (string) Str::uuid(), 'created_at' => now()];
    }
}
