<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Orders\OrderService;
use App\Services\Responsibilities\ResponsibilityScopeConflictEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class WarehouseSalesRulesTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_shared_warehouse_visibility_does_not_block_specific_accountability_or_create_ownership(): void
    {
        $f = $this->responsibilityFoundation(5);
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->warehouseScope($f, $f['employee']->user);
        $this->warehouseScope($f, $second);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $data = $this->assignmentData($f, overrides: [
            'employeeId' => $holder->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => $f['platform']->id, 'condition' => $f['product']->condition, 'assignStockByDefault' => true,
        ]);
        $this->assertCount(0, app(ResponsibilityScopeConflictEvaluator::class)->conflicts($data));
        app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
        [$account] = app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
        $this->assertSame($holder->employee->id, $account->employee_id);
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_warehouse_only_cannot_become_default_owner_even_with_the_flag(): void
    {
        $f = $this->responsibilityFoundation();
        try {
            $this->warehouseScope($f, $f['employee']->user, true);
            $this->fail('Warehouse-only cannot enable default ownership.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('requires a Brand, Category, or Product scope', $exception->getMessage());
        }
        $this->warehouseScope($f, $f['employee']->user);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No default stock responsibility');
        app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
    }

    public function test_specific_warehouse_dimensions_and_cross_platform_default_holders_still_conflict(): void
    {
        $f = $this->responsibilityFoundation();
        foreach (['brandId', 'categoryId', 'productId'] as $dimension) {
            $value = match ($dimension) {
                'brandId' => $f['brand']->id,
                'categoryId' => $f['product']->category_id,
                default => $f['product']->id,
            };
            $scope = ['brandId' => null, $dimension => $value, 'warehouseId' => $f['inventory']->warehouse_id];
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: $scope), $f['owner']);
            $other = $this->responsibilityUser(EmployeeRole::Staff);
            $conflicts = app(ResponsibilityScopeConflictEvaluator::class)->conflicts($this->assignmentData($f, overrides: $scope + ['employeeId' => $other->employee->id]));
            $this->assertTrue($conflicts->contains(fn ($conflict) => $conflict['operational']));
        }
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['platformId' => $f['platform']->id, 'assignStockByDefault' => true]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $platform = MarketplacePlatform::factory()->create();
        $conflicts = app(ResponsibilityScopeConflictEvaluator::class)->conflicts($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'platformId' => $platform->id, 'assignStockByDefault' => true]));
        $this->assertTrue($conflicts->contains(fn ($conflict) => $conflict['defaultStock']));
    }

    public function test_warehouse_visibility_requires_permission_and_is_not_allocation_ownership(): void
    {
        $f = $this->salesFoundation();
        $this->grant($f, $f['employee']->user, InventoryPermission::View);
        $anotherInventory = ProductInventory::factory()->create(['warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 2, 'reserved_quantity' => 0]);
        $anotherHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($anotherInventory, $f['owner']);
        $allocations->reconcile($anotherInventory, $allocations->employeeAccount($anotherHolder->employee->id), 2, $f['owner'], 'Another existing holder');
        $visible = app(InventoryReadService::class)->inventories($f['employee']->user)->pluck('product_inventories.id');
        $this->assertContains($f['inventory']->id, $visible);
        $this->assertContains($anotherInventory->id, $visible);
        $outside = ProductInventory::factory()->create();
        $this->assertNotContains($outside->id, $visible);
        $this->assertSame(5, $f['account']->balances()->sole()->allocated_quantity);
        $this->assertFalse(app(InventoryAllocationService::class)->canConsumeFromAccount($f['employee']->user, $f['account']));
        $this->grant($f, $f['employee']->user, InventoryPermission::View, EmployeePermissionEffect::Deny);
        $this->assertFalse(app(InventoryAuthorization::class)->allows($f['employee']->user, InventoryPermission::View, $f['inventory']));
    }

    public function test_unauthorized_source_submission_rolls_back_everything(): void
    {
        $f = $this->salesFoundation();
        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($f, $f['employee']->user), $f['employee']->user);
            $this->fail('Visibility cannot authorize consumption.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 0);
        $this->assertSame(0, $f['inventory']->refresh()->reserved_quantity);
        $this->assertSame(5, $f['account']->balances()->sole()->allocated_quantity);
    }

    public function test_cross_holder_permission_defaults_remain_owner_admin_only(): void
    {
        foreach (EmployeeRole::cases() as $role) {
            $actor = $this->responsibilityUser($role);
            $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), app(InventoryAuthorization::class)->allows($actor, InventoryPermission::ConsumeFromAllAllocations));
        }
    }

    public function test_two_warehouse_sellers_keep_their_own_credit_and_consume_the_exact_holder(): void
    {
        $f = $this->salesFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->warehouseScope($f, $second);
        foreach ([$f['employee']->user, $second] as $seller) {
            $this->grant($f, $seller, InventoryPermission::ConsumeFromAllAllocations);
            $data = $this->orderData($f, $seller);
            $order = app(SaveAndReserveOrder::class)->handle($data, $seller);
            $this->assertSame($order->id, app(SaveAndReserveOrder::class)->handle($data, $seller)->id);
            $this->assertSame($seller->employee->id, $order->handled_by_employee_id);
            $this->assertSame($f['account']->id, $order->items->sole()->reservation->allocationLines()->sole()->account_id);
            app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $f['owner']);
            $this->assertSame($seller->employee->id, $order->refresh()->handled_by_employee_id);
        }
        $this->assertSame(3, $f['account']->balances()->sole()->allocated_quantity);
        $this->assertSame(0, $f['account']->balances()->sole()->reserved_quantity);
        $this->assertSame(3, $f['inventory']->refresh()->available_quantity);
        $this->assertSame(0, Order::query()->where('handled_by_employee_id', $f['account']->employee_id)->count());
        $this->assertDatabaseCount('inventory_allocation_reservation_lines', 2);
    }

    public function test_staff_cannot_spoof_handler_and_later_draft_editor_preserves_original_handler(): void
    {
        $f = $this->salesFoundation();
        $seller = $f['employee']->user;
        try {
            app(OrderService::class)->saveDraft($this->orderData($f, $seller, handler: $f['account']->employee_id), $seller);
            $this->fail('Staff must not credit another employee.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handled_by_employee_id', $exception->errors());
        }
        $draft = app(OrderService::class)->saveDraft($this->orderData($f, $seller), $seller);
        $editor = $this->responsibilityUser(EmployeeRole::Staff);
        $this->warehouseScope($f, $editor);
        $updated = app(OrderService::class)->saveDraft($this->orderData($f, $editor, omitHandler: true), $editor, $draft);
        $this->assertSame($seller->employee->id, $updated->handled_by_employee_id);
    }

    public function test_cross_holder_permission_does_not_bypass_warehouse_or_availability_and_release_is_exact(): void
    {
        $f = $this->salesFoundation();
        $seller = $f['employee']->user;
        $this->grant($f, $seller, InventoryPermission::ConsumeFromAllAllocations);
        foreach ([$this->orderData($f, $seller, 6), $this->orderData($f, $seller, warehouse: Warehouse::factory()->create()->id)] as $invalid) {
            try {
                app(SaveAndReserveOrder::class)->handle($invalid, $seller);
                $this->fail('Invalid stock/warehouse must fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('orders', 0);
                $this->assertSame(0, $f['inventory']->refresh()->reserved_quantity);
            }
        }
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($f, $seller, 2), $seller);
        app(CancelOrder::class)->handle($order, new CancelOrderData('Customer cancelled', (string) Str::uuid()), $seller);
        $this->assertSame(5, $f['account']->balances()->sole()->allocated_quantity);
        $this->assertSame(0, $f['account']->balances()->sole()->reserved_quantity);
    }

    public function test_competing_authorized_sales_recheck_locked_balance_without_overselling(): void
    {
        $f = $this->salesFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->warehouseScope($f, $second);
        foreach ([$f['employee']->user, $second] as $seller) {
            $this->grant($f, $seller, InventoryPermission::ConsumeFromAllAllocations);
        }
        app(SaveAndReserveOrder::class)->handle($this->orderData($f, $f['employee']->user, 4), $f['employee']->user);
        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($f, $second, 2), $second);
            $this->fail('The second serialized writer must see the reservation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 1);
            $this->assertSame(4, $f['inventory']->refresh()->reserved_quantity);
            $this->assertSame(4, $f['account']->balances()->sole()->reserved_quantity);
            $this->assertSame(1, $f['account']->balances()->sole()->availableQuantity());
        }
    }

    public function test_low_level_manual_source_reservation_cannot_bypass_permission(): void
    {
        $f = $this->salesFoundation();
        $reservation = InventoryReservation::factory()->create(['product_inventory_id' => $f['inventory']->id, 'quantity' => 1]);
        try {
            DB::transaction(fn () => app(InventoryAllocationService::class)->reserveExact($reservation, [$f['account']->id => 1], $f['employee']->user, true));
            $this->fail('Direct manual reservation requires permission.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not authorized', $exception->getMessage());
        }
        $this->assertSame(0, InventoryAllocationReservationLine::query()->count());
        $this->assertSame(0, $f['account']->balances()->sole()->reserved_quantity);
    }

    public function test_simultaneous_sales_workers_cannot_overconsume_one_holder(): void
    {
        $f = $this->salesFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->warehouseScope($f, $second);
        $sellers = [$f['employee']->user, $second];
        foreach ($sellers as $seller) {
            $this->grant($f, $seller, InventoryPermission::ConsumeFromAllAllocations);
        }
        $path = sys_get_temp_dir().'/tpz-warehouse-sale-race-'.Str::uuid().'.sqlite';
        touch($path);
        $workers = [];
        try {
            // Copy ONLY disposable in-memory fixtures, never an active ERP database.
            $copy = new \PDO('sqlite:'.$path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $copy->exec('PRAGMA foreign_keys=OFF');
            foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $copy->exec($table->sql);
                foreach (DB::table($table->name)->get() as $row) {
                    $fields = array_keys((array) $row);
                    $statement = $copy->prepare('INSERT INTO "'.$table->name.'" ("'.implode('","', $fields).'") VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
                    $statement->execute(array_values((array) $row));
                }
            }
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND sql IS NOT NULL") as $object) {
                $copy->exec($object->sql);
            }
            $statement = $copy = null;
            foreach ($sellers as $slot => $seller) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/WarehouseSalesReservationWorker.php'), $path, (string) $seller->id, (string) $f['inventory']->id, (string) $f['account']->id, (string) $slot], base_path(), timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($path.'.ready.0') || ! is_file($path.'.ready.1')) && microtime(true) < $deadline) {
                foreach ($workers as $slot => $worker) {
                    if (! is_file($path.'.ready.'.$slot) && ! $worker->isRunning()) {
                        break 2;
                    }
                }
                usleep(10000);
            }
            $diagnostics = implode(' ', array_map(fn (Process $worker): string => $worker->getErrorOutput(), $workers));
            $this->assertFileExists($path.'.ready.0', $diagnostics);
            $this->assertFileExists($path.'.ready.1', $diagnostics);
            touch($path.'.go');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['result'];
            }
            sort($results);
            $this->assertSame(['created', 'rejected'], $results);
            $copy = new \PDO('sqlite:'.$path);
            $this->assertSame(1, (int) $copy->query('SELECT count(*) FROM orders')->fetchColumn());
            $this->assertSame(4, (int) $copy->query('SELECT reserved_quantity FROM product_inventories WHERE id='.$f['inventory']->id)->fetchColumn());
            $this->assertSame(4, (int) $copy->query('SELECT reserved_quantity FROM inventory_allocation_balances WHERE account_id='.$f['account']->id)->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT count(*) FROM inventory_allocation_reservation_lines')->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = $copy = null;
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal', $path.'.ready.0', $path.'.ready.1', $path.'.go'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    private function salesFoundation(): array
    {
        $f = $this->responsibilityFoundation(5);
        $this->warehouseScope($f, $f['employee']->user);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $account = $allocations->employeeAccount($holder->employee->id);
        $allocations->reconcile($f['inventory'], $account, 5, $f['owner'], 'Existing holder stock');

        return $f + compact('account');
    }

    private function warehouseScope(array $f, User $user, bool $default = false): void
    {
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $user->employee->id, 'brandId' => null, 'warehouseId' => $f['inventory']->warehouse_id, 'assignStockByDefault' => $default]), $f['owner']);
    }

    private function grant(array $f, User $user, InventoryPermission $permission, EmployeePermissionEffect $effect = EmployeePermissionEffect::Allow): void
    {
        app(EmployeePermissionOverrideService::class)->change($user->employee, $permission->value, $effect, 'Warehouse sales test authorization', $f['owner']);
    }

    private function orderData(array $f, User $seller, int $quantity = 1, ?int $warehouse = null, ?int $handler = null, bool $omitHandler = false): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData($warehouse ?? $f['inventory']->warehouse_id, null, null, today()->toDateString(), $omitHandler ? null : ($handler ?? $seller->employee->id), null, [new OrderItemData($f['product']->id, $quantity, '250.00', allocationSources: [$f['account']->id => $quantity])], (string) Str::uuid());
    }
}
