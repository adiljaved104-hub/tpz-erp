<?php

namespace Database\Factories;

use App\Enums\ComponentType;
use App\Enums\InventoryItemType;
use App\Models\Component;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Component> */
class ComponentFactory extends Factory
{
    protected $model = Component::class;

    public function definition(): array
    {
        return [
            'product_id' => fn (): int => Product::factory()->create(['inventory_item_type' => InventoryItemType::Component])->id,
            'component_type' => ComponentType::Ram,
            'specification' => '8GB DDR4 3200',
            'capacity_value' => '8.0000',
            'capacity_unit' => 'gb',
            'interface_type' => 'DDR4',
            'approved_oem_recovery_value' => '0.0000',
            'created_by_user_id' => fn (): int => User::factory()->create()->id,
            'updated_by_user_id' => fn (): int => User::factory()->create()->id,
        ];
    }
}
