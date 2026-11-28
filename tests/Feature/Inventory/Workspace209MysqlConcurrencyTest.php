<?php

namespace Tests\Feature\Inventory;

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityStockTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

/** Non-transactional CI schema; production reference guard stays enabled. */
class Workspace209MysqlConcurrencyTest extends TestCase
{
    use ResponsibilityTestFoundation;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'tpz_invoice_design_ci_test') {
            $this->markTestSkipped('Requires isolated MySQL CI schema.');
        }
        $this->assertSame(0, DB::transactionLevel());
        config(['reference_sequences.enforce_transaction_guard' => true]);
    }

    public function test_concurrent_release_and_duplicate_creation_with_production_reference_enforcement(): void
    {
        $actor = $this->responsibilityUser(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $product = Product::factory()->create();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 10, 'reserved_quantity' => 0]);
        $data = new SaveAndReserveOrderData($warehouse->id, null, null, today()->toDateString(), $actor->employee->id, null, [new OrderItemData($product->id, 2, '150.00')], (string) Str::uuid());
        $order = app(SaveAndReserveOrder::class)->handle($data, $actor);
        $key = (string) Str::uuid();
        $args = ['release', (string) $actor->id, (string) $order->id, $key];
        $workers = [$this->worker($args), $this->worker($args)];
        foreach ($workers as $worker) {
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        $this->assertSame(0, $inventory->fresh()->reserved_quantity);
        $this->assertSame(10, $inventory->fresh()->available_quantity);
        $this->assertSame('draft', $order->fresh()->status->value);
        $reservation = $order->items()->first()->reservation;
        $this->assertSame(1, StockMovement::query()->where('source_type', $reservation->getMorphClass())->where('source_id', $reservation->id)->where('reserved_delta', '<', 0)->count());

        $platform = MarketplacePlatform::factory()->create(['status' => true]);
        $external = 'CI-CONCURRENT-'.Str::uuid();
        $args = ['create', (string) $actor->id, (string) $warehouse->id, (string) $platform->id, $external, (string) $product->id];
        $workers = [$this->worker($args), $this->worker($args)];
        foreach ($workers as $worker) {
            $worker->start();
        }
        $codes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $codes[] = $worker->getExitCode();
        }
        sort($codes);
        $this->assertSame([0, 2], $codes, 'One request must succeed and one must be rejected without duplicate stock reservation.');
        $this->assertSame(1, Order::query()->where('external_order_number', $external)->count());
        $this->assertSame(1, $inventory->fresh()->reserved_quantity);
    }

    private function worker(array $args): Process
    {
        return new Process([PHP_BINARY, base_path('tests/Support/Workspace209ConcurrentWorker.php'), ...$args], base_path(), null, null, 90);
    }

    public function test_concurrent_brand_handoff_with_production_reference_guard_moves_stock_once(): void
    {
        // Committed fixtures coexist across these nontransactional tests. Do not
        // reuse the transactional helper's fixed HP brand or reset the schema.
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $employee = $this->responsibilityUser(EmployeeRole::Staff)->employee;
        $brand = ProductBrand::factory()->create(['name' => 'Concurrency '.Str::uuid()]);
        $product = Product::factory()->create(['brand' => $brand->name, 'brand_id' => $brand->id]);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 10, 'reserved_quantity' => 0]);
        $f = compact('owner', 'employee', 'brand', 'product', 'inventory');
        $source = app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $ledger = app(InventoryAllocationService::class);
        $account = $ledger->employeeAccount($f['employee']->id);
        InventoryAllocationBalance::create(['account_id' => $account->id, 'product_inventory_id' => $f['inventory']->id, 'allocated_quantity' => 10, 'reserved_quantity' => 0]);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $plan = app(ResponsibilityStockTransitionService::class)->preview($source, $f['owner']);
        $key = (string) Str::uuid();
        $args = ['brand', (string) $f['owner']->id, (string) $source->id, (string) $destination->employee->id, $key, json_encode($plan, JSON_THROW_ON_ERROR)];
        $workers = [$this->worker($args), $this->worker($args)];
        foreach ($workers as $worker) {
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
        }
        $this->assertSame(10, $f['inventory']->fresh()->available_quantity);
        $this->assertSame(0, (int) $account->balances()->sum('allocated_quantity'));
        $this->assertSame(10, (int) $ledger->employeeAccount($destination->employee->id)->balances()->sum('allocated_quantity'));
        $this->assertSame(1, ResponsibilityAssignment::query()->where('idempotency_key', $key)->count());
    }
}
