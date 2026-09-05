<?php

namespace Tests\Feature\ProductIntelligence;

use App\Enums\EmployeeRole;
use App\Enums\InventoryItemType;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ProductSelectorIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_sales_uses_bounded_stock_aware_product_intelligence_and_excludes_components(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $warehouse->forceFill(['is_default' => true, 'status' => true])->save();
        $product = Product::factory()->create(['sku' => 'TPZ-WEB-840', 'name' => 'HP EliteBook 840 G8 i5 16GB 512GB']);
        $component = Product::factory()->create(['sku' => 'RAM-WEB-16', 'name' => '16GB DDR4 RAM', 'inventory_item_type' => InventoryItemType::Component]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 3]);
        ProductInventory::factory()->create(['product_id' => $component->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 10]);
        $this->actingAs($owner);

        $field = collect(Livewire::test(CreateWebSalesOrder::class)->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertInstanceOf(Select::class, $field);
        $this->assertSame([], $field->getOptions());
        $results = $field->getSearchResults('HP 840 G8 16 512');
        $this->assertArrayHasKey($product->id, $results);
        $this->assertStringContainsString('Sellable: 3', $results[$product->id]);
        $this->assertArrayNotHasKey($component->id, $field->getSearchResults('RAM-WEB-16'));
    }

    public function test_quotation_uses_shared_advisory_search_without_reserving_stock_or_components(): void
    {
        $owner = $this->owner();
        $brand = ProductBrand::factory()->create(['name' => 'Lenovo', 'normalized_name' => 'lenovo']);
        $product = Product::factory()->create(['sku' => 'TPZ-QT-T14', 'name' => 'Lenovo ThinkPad T14 Gen 2 i5 16GB 512GB', 'brand' => 'Lenovo', 'brand_id' => $brand->id, 'model' => 'T14 Gen 2', 'ram' => '16GB', 'storage' => '512GB']);
        $component = Product::factory()->create(['sku' => 'SSD-QT-512', 'name' => '512GB NVMe SSD', 'inventory_item_type' => InventoryItemType::Component]);
        $this->actingAs($owner);

        $field = collect(Livewire::test(CreateQuotation::class)->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $results = $field->getSearchResults('Lenovo T14 Gen 2 16/512');

        $this->assertArrayHasKey($product->id, $results);
        $this->assertStringContainsString('Model: T14GEN2', $results[$product->id]);
        $this->assertArrayNotHasKey($component->id, $field->getSearchResults('SSD-QT-512'));
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function owner(): User
    {
        $email = 'selector-owner-'.Str::lower(Str::random(8)).'@example.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $email]);

        return $user->refresh();
    }
}
