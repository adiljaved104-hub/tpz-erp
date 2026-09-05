<?php

namespace Tests\Feature\Components;

use App\Actions\Purchases\QuickStockPurchase;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\EmployeeRole;
use App\Models\Component;
use App\Models\Employee;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComponentPurchaseIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_component_purchase_receipts_reuse_inventory_movement_and_weighted_average_cost(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $component = Component::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->receive($component, $warehouse, $owner, 2, '100.0000');
        $this->receive($component, $warehouse, $owner, 2, '200.0000');

        $inventory = ProductInventory::query()->where('product_id', $component->product_id)->where('warehouse_id', $warehouse->id)->firstOrFail();
        $this->assertSame(4, $inventory->available_quantity);
        $this->assertSame('150.0000', $inventory->average_cost);
        $this->assertSame(2, StockMovement::query()->where('product_id', $component->product_id)->count());
    }

    public function test_component_cannot_be_submitted_as_a_normal_sales_order_product(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $component = Component::factory()->create();
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $component->product_id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 5, 'average_cost' => '10.0000',
        ]);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->saveAndReserve(new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: null,
            externalOrderNumber: 'COMPONENT-SALES-BLOCK',
            orderDate: now()->toDateString(),
            handledByEmployeeId: $owner->employee->id,
            notes: null,
            items: [new OrderItemData($component->product_id, 1, '50.00')],
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
    }

    private function receive(Component $component, Warehouse $warehouse, User $owner, int $quantity, string $cost): void
    {
        app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
            warehouseId: $warehouse->id,
            purchaseDate: now()->toDateString(),
            items: [new PurchaseItemData($component->product_id, $quantity, $cost)],
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
