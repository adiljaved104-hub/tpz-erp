<?php

namespace Database\Factories;

use App\Enums\PurchaseEntryType;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Purchase> */
class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    public function definition(): array
    {
        return [
            'reference' => 'PO-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'supplier_id' => Supplier::factory(), 'warehouse_id' => Warehouse::factory(),
            'external_accounting_reference' => null,
            'entry_type' => PurchaseEntryType::Standard,
            'handled_by_employee_id' => null,
            'purchase_date' => now()->toDateString(), 'currency' => 'AED', 'status' => PurchaseStatus::Draft,
            'subtotal' => '0.00', 'discount_total' => '0.00', 'net_before_vat' => '0.00',
            'shipping_total' => '0.00', 'shipping_vat_rate' => '0.00', 'shipping_vat_amount' => '0.00',
            'other_charges_total' => '0.00', 'other_charges_vat_rate' => '0.00', 'other_charges_vat_amount' => '0.00',
            'vat_total' => '0.00', 'grand_total' => '0.00', 'created_by_user_id' => User::factory(), 'self_approved' => false,
        ];
    }
}
