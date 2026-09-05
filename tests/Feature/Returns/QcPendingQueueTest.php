<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Inventory\QcPending;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Returns\QcPendingQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class QcPendingQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_shows_only_received_items_with_pending_quantity_and_supports_partial_inspection(): void
    {
        $f = $this->foundation('QUEUE-SKU', 'Queue Laptop', 3);
        $draft = $this->createReturn($f, 1);
        $received = $this->createReturn($f, 2);
        app(ReceiveCustomerReturn::class)->handle($received, $f['owner']);

        $service = app(QcPendingQueueService::class);
        $rows = $service->paginate($f['owner'], [])->items();
        $this->assertCount(1, $rows);
        $this->assertSame($received->id, $rows[0]['customer_return_id']);
        $this->assertSame(2, $rows[0]['received_quantity']);
        $this->assertSame(0, $rows[0]['inspected_quantity']);
        $this->assertSame(2, $rows[0]['pending_quantity']);

        app(InspectCustomerReturnItem::class)->handle($received->items->first(), new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $f['owner']);
        $partial = $service->paginate($f['owner'], [])->items();
        $this->assertSame(1, $partial[0]['inspected_quantity']);
        $this->assertSame(1, $partial[0]['pending_quantity']);

        app(InspectCustomerReturnItem::class)->handle($received->items->first(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $this->assertSame([], $service->paginate($f['owner'], [])->items());
        $this->assertNotNull($draft->fresh());
    }

    public function test_search_location_filters_summary_and_operational_projection(): void
    {
        $f = $this->foundation('FILTER-SKU', 'Filter Model', 2);
        $return = $this->createReturn($f, 2);
        app(ReceiveCustomerReturn::class)->handle($return, $f['owner']);
        $service = app(QcPendingQueueService::class);

        $this->assertCount(1, $service->paginate($f['owner'], ['search' => 'FILTER-SKU'])->items());
        $this->assertCount(1, $service->paginate($f['owner'], ['search' => 'Filter Model'])->items());
        $this->assertCount(1, $service->paginate($f['owner'], ['warehouse_id' => $f['warehouse']->id])->items());
        $this->assertCount(0, $service->paginate($f['owner'], ['warehouse_id' => Warehouse::factory()->create()->id])->items());
        $this->assertSame(['returns' => 1, 'units' => 2], array_intersect_key($service->summary($f['owner'], []), array_flip(['returns', 'units'])));

        $sql = strtolower($service->query($f['owner'])->toSql());
        foreach (['inventory_unit_cost', 'average_cost', 'qc_pending_value', 'cogs', 'profit', 'inventory_value'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_staff_visibility_and_inspection_require_permission_and_active_responsibility(): void
    {
        $f = $this->foundation('SCOPED-SKU', 'Scoped Product', 1);
        $return = $this->createReturn($f, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $f['owner']);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $staffUser->refresh();
        $service = app(QcPendingQueueService::class);

        $this->assertCount(0, $service->paginate($staffUser, [])->items());
        foreach (['return.view', 'return.inspect'] as $permission) {
            EmployeePermissionOverride::query()->create(['employee_id' => $staff->id, 'permission_key' => $permission, 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id, 'reason' => 'QC queue test']);
        }
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->id);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);

        $this->assertCount(1, $service->paginate($staffUser, [])->items());
        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $staffUser);
        $this->assertCount(0, $service->paginate($staffUser, [])->items());
    }

    public function test_page_renders_operational_queue_and_inspect_link_for_authorized_owner(): void
    {
        $f = $this->foundation('PAGE-SKU', 'Page Product', 1);
        $return = $this->createReturn($f, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $f['owner']);

        $this->actingAs($f['owner']);
        Livewire::test(QcPending::class)
            ->assertSee('Pending QC Returns')
            ->assertSee('PAGE-SKU')
            ->assertSee('Page Product')
            ->assertSee('Inspect');
    }

    /** @return array<string, mixed> */
    private function foundation(string $sku, string $name, int $quantity): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create(['sku' => $sku, 'name' => $name]);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData($warehouse->id, null, null, now()->toDateString(), $owner->employee->id, null, [new OrderItemData($product->id, $quantity, '200.00')], (string) Str::uuid()), $owner)->load('fulfillment.items');

        return compact('owner', 'product', 'warehouse', 'order');
    }

    private function createReturn(array $f, int $quantity): CustomerReturn
    {
        return app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData($f['order']->id, $f['warehouse']->id, [['order_fulfillment_item_id' => $f['order']->fulfillment->items->first()->id, 'quantity' => $quantity, 'return_reason' => 'other']], (string) Str::uuid()), $f['owner']);
    }
}
