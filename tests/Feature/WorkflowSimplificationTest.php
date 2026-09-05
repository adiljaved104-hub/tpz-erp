<?php

namespace Tests\Feature;

use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\Pages\ReceivePurchase;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowSimplificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_remaining_fills_outstanding_quantities_without_posting_a_grn(): void
    {
        $owner = $this->owner();
        $purchase = Purchase::factory()->create([
            'status' => PurchaseStatus::Approved,
            'created_by_user_id' => $owner->id,
        ]);
        $item = PurchaseItem::factory()->create([
            'purchase_id' => $purchase->id,
            'ordered_quantity' => 7,
            'received_quantity' => 2,
            'rejected_quantity' => 1,
        ]);

        $component = Livewire::actingAs($owner)->test(ReceivePurchase::class, ['record' => $purchase->getRouteKey()])
            ->assertSee('Receive Remaining')
            ->call('receiveRemaining')
            ->assertNotified('Remaining quantities filled');
        $formItem = collect($component->get('data.items'))->firstWhere('purchase_item_id', $item->id);

        $this->assertSame(5, $formItem['accepted_quantity']);
        $this->assertSame(0, $formItem['damaged_quantity']);
        $this->assertSame(0, $formItem['rejected_quantity']);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertSame(2, $item->fresh()->received_quantity);
    }

    public function test_stock_transfer_defaults_known_context_and_only_offers_sellable_source_products(): void
    {
        $owner = $this->owner();
        $source = Warehouse::query()->where('code', 'MAIN')->firstOrFail();
        $source->forceFill(['is_default' => true, 'location_type' => InventoryLocationType::CompanyWarehouse])->save();
        $sellable = Product::factory()->create(['sku' => 'SELLABLE-SKU']);
        $reserved = Product::factory()->create(['sku' => 'RESERVED-SKU']);
        ProductInventory::factory()->create(['warehouse_id' => $source->id, 'product_id' => $sellable->id, 'available_quantity' => 3, 'reserved_quantity' => 1]);
        ProductInventory::factory()->create(['warehouse_id' => $source->id, 'product_id' => $reserved->id, 'available_quantity' => 2, 'reserved_quantity' => 2]);

        Livewire::actingAs($owner)->test(CreateStockTransfer::class)
            ->assertFormSet(['source_warehouse_id' => $source->id, 'handled_by_employee_id' => $owner->employee->id])
            ->assertSee('SELLABLE-SKU')
            ->assertDontSee('RESERVED-SKU');
    }

    public function test_responsibility_type_controls_fields_and_server_discards_irrelevant_tampered_scope(): void
    {
        $owner = $this->owner();
        $brand = ProductBrand::factory()->create();
        $unrelatedProduct = Product::factory()->create();

        Livewire::actingAs($owner)->test(CreateResponsibilityAssignment::class)
            ->assertFormSet(['scope_type' => 'brand'])
            ->assertSchemaComponentHidden('platform_id')
            ->assertSchemaComponentHidden('product_id')
            ->assertSchemaComponentHidden('product_inventory_id');

        $scope = CreateResponsibilityAssignment::normalizedScope([
            'scope_type' => 'brand', 'brand_id' => $brand->id,
            'product_id' => $unrelatedProduct->id, 'platform_id' => 999,
        ]);
        $this->assertSame($brand->id, $scope['brand_id']);
        $this->assertNull($scope['product_id']);
        $this->assertNull($scope['platform_id']);
        $this->assertNull($scope['assigned_quantity']);
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create([
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
