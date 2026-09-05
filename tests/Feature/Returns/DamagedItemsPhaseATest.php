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
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Exceptions\CustomerReturnException;
use App\Exceptions\ImmutableInventoryRecordException;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnInspection;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\DamagedItemsQueueService;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DamagedItemsPhaseATest extends TestCase
{
    use RefreshDatabase;

    public function test_return_qc_damage_creates_one_immutable_event_and_sellable_qc_does_not(): void
    {
        $f = $this->foundation('DMG-QC-1', 'Damage QC Product', 2);
        $return = $this->createAndReceiveReturn($f, 2);
        $postingKey = (string) Str::uuid();

        app(InspectCustomerReturnItem::class)->handle(
            $return->items->sole(),
            new InspectCustomerReturnItemData(1, 1, $postingKey, 'Screen damage'),
            $f['owner'],
        );

        $event = DamagedStockEvent::query()->sole();
        $this->assertMatchesRegularExpression('/^DMG-\d{4}-\d{6}$/', $event->reference);
        $this->assertSame(1, $event->quantity);
        $this->assertSame(DamagedStockSource::CustomerReturn, $event->source);
        $this->assertSame(DamagedStockStatus::Damaged, $event->status);
        $this->assertSame(
            $event->customer_return_inspection_id,
            CustomerReturnInspection::query()->where('posting_key', $event->idempotency_key)->value('id'),
        );
        $this->assertSame($return->id, $event->customer_return_id);
        $this->assertSame($return->order_id, $event->order_id);
        $this->assertSame(1, $f['inventory']->refresh()->damaged_quantity);
        $this->assertDatabaseHas('activity_logs', ['event' => 'damaged_stock.created', 'subject_id' => $event->id]);

        try {
            app(InspectCustomerReturnItem::class)->handle(
                $return->items->sole(),
                new InspectCustomerReturnItemData(0, 1, $postingKey, 'Retry'),
                $f['owner'],
            );
            $this->fail('Duplicate QC retry should be rejected.');
        } catch (CustomerReturnException) {
        }
        $this->assertDatabaseCount('damaged_stock_events', 1);

        $sellable = $this->foundation('DMG-QC-2', 'Sellable QC Product', 1);
        $sellableReturn = $this->createAndReceiveReturn($sellable, 1);
        app(InspectCustomerReturnItem::class)->handle(
            $sellableReturn->items->sole(),
            new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()),
            $sellable['owner'],
        );
        $this->assertDatabaseCount('damaged_stock_events', 1);
    }

    public function test_reference_is_reserved_before_the_qc_transaction(): void
    {
        $f = $this->foundation('DMG-REF', 'Reference Product', 1);
        $return = $this->createAndReceiveReturn($f, 1);
        $probe = new class extends ReferenceSequenceService
        {
            /** @var array<string, int> */
            public array $levels = [];

            public function nextStockMovementReference(): string
            {
                $this->levels['movement'] = DB::transactionLevel();

                return 'SM-990001';
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->levels['damage'] = DB::transactionLevel();

                return 'DMG-2026-990001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambient = DB::transactionLevel();

        app(InspectCustomerReturnItem::class)->handle(
            $return->items->sole(),
            new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()),
            $f['owner'],
        );

        $this->assertSame(['movement' => $ambient, 'damage' => $ambient], $probe->levels);
    }

    public function test_queue_combines_structured_and_unmatched_old_damage_without_financial_fields(): void
    {
        $f = $this->foundation('DMG-OLD', 'Old Damage Product', 1);
        $f['inventory']->forceFill(['damaged_quantity' => 4])->save();
        DamagedStockEvent::query()->create([
            'reference' => 'DMG-2026-000099', 'product_inventory_id' => $f['inventory']->id,
            'product_id' => $f['product']->id, 'warehouse_id' => $f['warehouse']->id, 'quantity' => 1,
            'source' => DamagedStockSource::WarehouseDamage, 'reason' => 'Known handling damage',
            'occurred_at' => now()->subDay(), 'reported_by_user_id' => $f['owner']->id,
            'status' => DamagedStockStatus::Damaged, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $resolved = DamagedStockEvent::query()->create([
            'reference' => 'DMG-2026-000100', 'product_inventory_id' => $f['inventory']->id,
            'product_id' => $f['product']->id, 'warehouse_id' => $f['warehouse']->id, 'quantity' => 1,
            'source' => DamagedStockSource::Other, 'reason' => 'Historical resolved damage',
            'occurred_at' => now()->subDays(2), 'reported_by_user_id' => $f['owner']->id,
            'status' => DamagedStockStatus::Resolved, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $service = app(DamagedItemsQueueService::class);
        $rows = $service->paginate($f['owner'], ['status' => 'damaged'])->items();

        $this->assertCount(2, $rows);
        $this->assertSame(3, collect($rows)->firstWhere('source', 'legacy')['quantity']);
        $this->assertSame('Old Damaged Stock', collect($rows)->firstWhere('source', 'legacy')['source_label']);
        $this->assertCount(1, $service->paginate($f['owner'], ['status' => 'resolved'])->items());
        $this->assertSame(4, $f['inventory']->refresh()->damaged_quantity);
        $summary = $service->summary($f['owner'], ['status' => 'damaged']);
        $this->assertSame($f['inventory']->damaged_quantity, $summary['units']);
        $this->assertLessThanOrEqual($f['inventory']->damaged_quantity, collect($rows)->sum('quantity'));
        $this->assertNotNull($resolved->fresh());

        $sql = strtolower($service->query($f['owner'])->toSql());
        foreach (['average_cost', 'inventory_value', 'cogs', 'profit', 'claim_amount'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }

        $this->actingAs($f['owner']);
        Livewire::test(DamagedItems::class)->assertSee('Damaged Items')->assertSee('DMG-OLD')->assertSee('Old Damaged Stock');
    }

    public function test_staff_requires_permission_and_only_sees_responsibility_scoped_products(): void
    {
        $f = $this->foundation('DMG-SCOPE-A', 'Assigned Damage', 1);
        $other = Product::factory()->create(['sku' => 'DMG-SCOPE-B', 'name' => 'Unassigned Damage']);
        ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['warehouse']->id, 'damaged_quantity' => 2]);
        $f['inventory']->forceFill(['damaged_quantity' => 1])->save();
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $staffUser->refresh();

        $this->actingAs($staffUser);
        Livewire::test(DamagedItems::class)->assertForbidden();

        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->id, 'permission_key' => 'damaged_stock.view',
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id,
            'reason' => 'Operational damaged queue',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->id);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);

        $rows = app(DamagedItemsQueueService::class)->paginate($staffUser, ['status' => 'damaged'])->items();
        $this->assertCount(1, $rows);
        $this->assertSame('DMG-SCOPE-A', $rows[0]['sku']);
        Livewire::test(DamagedItems::class)->assertOk()->assertSee('DMG-SCOPE-A')->assertDontSee('DMG-SCOPE-B');
    }

    public function test_database_rejects_non_positive_damage_quantities_and_history_is_immutable(): void
    {
        $f = $this->foundation('DMG-CHECK', 'Constraint Product', 1);
        $base = [
            'product_inventory_id' => $f['inventory']->id, 'product_id' => $f['product']->id,
            'warehouse_id' => $f['warehouse']->id, 'source' => 'internal_warehouse',
            'reason' => 'Constraint test', 'occurred_at' => now(), 'reported_by_user_id' => $f['owner']->id,
            'status' => 'damaged', 'created_at' => now(), 'updated_at' => now(),
        ];

        foreach ([0, -1] as $quantity) {
            try {
                DB::table('damaged_stock_events')->insert($base + [
                    'reference' => 'DMG-CHECK-'.abs($quantity), 'quantity' => $quantity,
                    'idempotency_key' => (string) Str::uuid(),
                ]);
                $this->fail("Quantity {$quantity} should fail the database CHECK constraint.");
            } catch (QueryException) {
            }
        }

        $event = DamagedStockEvent::query()->create($base + [
            'reference' => 'DMG-CHECK-1', 'quantity' => 1, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->expectException(ImmutableInventoryRecordException::class);
        $event->update(['reason' => 'Changed']);
    }

    /** @return array<string, mixed> */
    private function foundation(string $sku, string $name, int $quantity): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create(['sku' => $sku, 'name' => $name]);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 5, 'damaged_quantity' => 0, 'average_cost' => '100.0000',
        ]);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, null, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($product->id, $quantity, '200.00')], (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');

        return compact('owner', 'product', 'warehouse', 'inventory', 'order');
    }

    private function createAndReceiveReturn(array $foundation, int $quantity): CustomerReturn
    {
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $foundation['order']->id, $foundation['warehouse']->id,
            [['order_fulfillment_item_id' => $foundation['order']->fulfillment->items->sole()->id, 'quantity' => $quantity, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $foundation['owner']);
        app(ReceiveCustomerReturn::class)->handle($return, $foundation['owner']);

        return $return->refresh()->load('items');
    }
}
