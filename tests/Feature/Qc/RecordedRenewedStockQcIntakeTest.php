<?php

namespace Tests\Feature\Qc;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\QcPermission;
use App\Enums\StockMovementType;
use App\Filament\Pages\Qc\RecordedRenewedStockQueue;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Filament\Resources\QcInspections\Widgets\QcProgress;
use App\Models\Employee;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceiptCorrection;
use App\Models\QcInspection;
use App\Models\QcRecordedStockIntake;
use App\Models\QcRecordedStockIntakeAdjustment;
use App\Models\QcRecordedStockIntakeRelease;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Orders\OrderService;
use App\Services\Purchases\PurchaseReceivingService;
use App\Services\Qc\QcAvailableCertificateService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcRecordedRenewedStockIntakeService;
use App\Services\Qc\QcRecordedStockIntakeAdjustmentService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecordedRenewedStockQcIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_only_renewed_accepted_grn_quantity_enters_qc_pending_with_its_own_value_and_movement(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $renewed = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $renewed, $warehouse, 9, '100.0000');
        $receipt = $this->receive($owner, $purchase, 3, 2, 4);

        $inventory = ProductInventory::query()->where('product_id', $renewed->id)->sole();
        $this->assertSame(0, $inventory->available_quantity);
        $this->assertSame(2, $inventory->damaged_quantity);
        $this->assertSame(3, $inventory->qc_pending_quantity);
        $this->assertSame('300.0000', $inventory->qc_pending_value);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertDatabaseHas('qc_recorded_stock_intakes', [
            'purchase_receipt_item_id' => $receipt->items->sole()->id,
            'accepted_quantity' => 3,
            'started_quantity' => 0,
            'released_quantity' => 0,
            'allocation_account_id' => InventoryAllocationAccount::query()
                ->where('identity_key', 'employee:'.$owner->employee->id)
                ->value('id'),
        ]);
        $this->assertDatabaseMissing('inventory_allocation_balances', ['product_inventory_id' => ProductInventory::query()->where('product_id', $renewed->id)->value('id')]);
        $movement = StockMovement::query()->sole();
        $this->assertSame(StockMovementType::RenewedQcIntake, $movement->movement_type);
        $this->assertSame(0, $movement->available_delta);
        $this->assertSame(2, $movement->damaged_delta);
        $this->assertSame(3, $movement->bucketChanges()->where('bucket', 'qc_pending')->value('quantity_delta'));
        $this->assertSame('300.0000', (string) $movement->bucketChanges()->where('bucket', 'qc_pending')->value('value_delta'));
        $this->assertSame(4, $receipt->items->sole()->rejected_quantity);

        $newProduct = $this->product(ProductCondition::New);
        $newPurchase = $this->approvedPurchase($owner, $newProduct, $warehouse, 2, '25.0000');
        $this->receive($owner, $newPurchase, 2, 0, 0);
        $newInventory = ProductInventory::query()->where('product_id', $newProduct->id)->sole();
        $this->assertSame(2, $newInventory->available_quantity);
        $this->assertSame(0, $newInventory->qc_pending_quantity);
        $this->assertDatabaseMissing('qc_recorded_stock_intakes', ['product_id' => $newProduct->id]);

        $rejectedProduct = $this->product(ProductCondition::Renewed);
        $rejectedPurchase = $this->approvedPurchase($owner, $rejectedProduct, $warehouse, 2, '40.0000');
        $this->receive($owner, $rejectedPurchase, 0, 0, 2);
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $rejectedProduct->id]);
        $this->assertDatabaseMissing('qc_recorded_stock_intakes', ['product_id' => $rejectedProduct->id]);
    }

    public function test_owner_can_reduce_recorded_intake_without_rewriting_the_grn_and_with_immutable_movement_audit(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '100.0000');
        $receipt = $this->receive($owner, $purchase, 5, 0, 0);
        $receiptItem = $receipt->items->sole();
        $intake = QcRecordedStockIntake::query()->sole();
        $inventory = ProductInventory::query()->where('product_id', $product->id)->sole();
        $purchaseStatusBefore = $purchase->fresh()->status;
        $availableBefore = $inventory->available_quantity;
        $damagedBefore = $inventory->damaged_quantity;
        $key = (string) Str::uuid();

        $adjustment = app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intake->id, $owner, [
            'adjustment_quantity' => 2,
            'reason' => 'Two units were not physically present at QC intake recount.',
            'idempotency_key' => $key,
        ]);

        $this->assertSame(5, $receiptItem->fresh()->accepted_quantity);
        $this->assertSame(3, $intake->fresh()->accepted_quantity);
        $this->assertSame(3, $inventory->fresh()->qc_pending_quantity);
        $this->assertSame('300.0000', $inventory->fresh()->qc_pending_value);
        $this->assertSame($availableBefore, $inventory->fresh()->available_quantity);
        $this->assertSame($damagedBefore, $inventory->fresh()->damaged_quantity);
        $this->assertSame($purchaseStatusBefore, $purchase->fresh()->status);
        $this->assertDatabaseMissing('inventory_allocation_balances', ['product_inventory_id' => $inventory->id]);
        $this->assertSame(5, $adjustment->quantity_before);
        $this->assertSame(2, $adjustment->adjustment_quantity);
        $this->assertSame(3, $adjustment->quantity_after);
        $this->assertSame('100.0000', $adjustment->unit_cost);
        $this->assertSame('200.0000', $adjustment->value_reduction);
        $this->assertSame($owner->id, $adjustment->performed_by_user_id);
        $this->assertDatabaseHas('stock_movement_bucket_changes', [
            'stock_movement_id' => $adjustment->stock_movement_id,
            'bucket' => 'qc_pending',
            'quantity_delta' => -2,
            'quantity_before' => 5,
            'quantity_after' => 3,
            'value_delta' => '-200.0000',
            'value_before' => '500.0000',
            'value_after' => '300.0000',
        ]);
        $movement = $adjustment->movement;
        $this->assertSame(StockMovementType::RenewedQcIntakeAdjustment, $movement->movement_type);
        $this->assertSame(2, $movement->quantity);
        $this->assertSame(0, $movement->available_delta);
        $this->assertSame(0, $movement->damaged_delta);
        $this->assertSame($intake->getMorphClass(), $movement->source_type);
        $this->assertSame($intake->id, $movement->source_id);
        $this->assertSame(1, QcRecordedStockIntakeAdjustment::query()->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::RenewedQcIntakeAdjustment)->count());

        try {
            $adjustment->forceFill(['reason' => 'rewritten'])->save();
            $this->fail('Recorded intake adjustment must reject updates.');
        } catch (\LogicException $exception) {
            $this->assertSame('Recorded QC intake adjustments are immutable.', $exception->getMessage());
        }
        try {
            $adjustment->delete();
            $this->fail('Recorded intake adjustment must reject deletion.');
        } catch (\LogicException $exception) {
            $this->assertSame('Recorded QC intake adjustments are immutable.', $exception->getMessage());
        }

        Livewire::actingAs($owner)->test(RecordedRenewedStockQueue::class)->assertSee('Adjust Recorded QC Intake');
    }

    public function test_intake_adjustment_preserves_started_and_released_unit_counts(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '70.0000');
        $this->receive($owner, $purchase, 5, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'ADJUST-START-001', $intake->id), $technician);
        app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'ADJUST-START-002', $intake->id), $technician);

        $service = app(QcRecordedStockIntakeAdjustmentService::class);
        $allowed = $service->adjust($intake->id, $owner, [
            'adjustment_quantity' => 2,
            'reason' => 'Recount confirmed two unclaimed units were absent.',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertSame(3, $allowed->quantity_after);
        $this->assertSame(2, $intake->fresh()->started_quantity);
        $this->assertSame(1, $intake->fresh()->accepted_quantity - $intake->fresh()->started_quantity);

        $this->assertValidation(fn () => $service->adjust($intake->id, $owner, [
            'adjustment_quantity' => 2,
            'reason' => 'This would reduce below two started jobs.',
            'idempotency_key' => (string) Str::uuid(),
        ]), 'adjustment_quantity');
        $this->assertSame(3, $intake->fresh()->accepted_quantity);
        $this->assertSame(3, ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_quantity);
    }

    public function test_unstarted_recorded_intake_may_be_reduced_to_zero_without_changing_grn_history(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 1, '12.5000');
        $receipt = $this->receive($owner, $purchase, 1, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();

        app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intake->id, $owner, [
            'adjustment_quantity' => 1,
            'reason' => 'Recount confirmed this recorded unit was not physically received.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(0, $intake->fresh()->accepted_quantity);
        $this->assertSame(1, $receipt->items->sole()->fresh()->accepted_quantity);
        $this->assertSame(0, ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_quantity);
        $this->assertSame('0.0000', ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_value);
        $this->assertSame(0, $intake->fresh()->started_quantity);
    }

    public function test_adjustment_authorization_is_owner_admin_only_and_idempotent(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $admin = $this->actor(EmployeeRole::Admin);
        $staff = $this->actor(EmployeeRole::Staff);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 4, '25.0000');
        $this->receive($owner, $purchase, 4, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $service = app(QcRecordedStockIntakeAdjustmentService::class);

        foreach ([$staff, $technician] as $unauthorized) {
            try {
                $service->adjust($intake->id, $unauthorized, [
                    'adjustment_quantity' => 1,
                    'reason' => 'Unauthorized adjustment attempt.',
                    'idempotency_key' => (string) Str::uuid(),
                ]);
                $this->fail('Staff and Technician must not adjust recorded QC intake.');
            } catch (AuthorizationException) {
                $this->assertFalse($service->canAdjust($unauthorized));
            }
        }

        $key = (string) Str::uuid();
        $payload = ['adjustment_quantity' => 1, 'reason' => 'One unit missing after recount.', 'idempotency_key' => $key];
        $ownerAdjustment = $service->adjust($intake->id, $owner, $payload);
        $replayed = $service->adjust($intake->id, $owner, $payload);
        $this->assertSame($ownerAdjustment->id, $replayed->id);
        $this->assertSame(3, $intake->fresh()->accepted_quantity);
        $this->assertSame(1, QcRecordedStockIntakeAdjustment::query()->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::RenewedQcIntakeAdjustment)->count());

        $secondPurchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '40.0000');
        $this->receive($owner, $secondPurchase, 2, 0, 0);
        $secondIntake = QcRecordedStockIntake::query()->where('id', '!=', $intake->id)->sole();
        $adminAdjustment = $service->adjust($secondIntake->id, $admin, [
            'adjustment_quantity' => 1,
            'reason' => 'Admin verified a short recorded count.',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertSame(1, $adminAdjustment->performed_by_user_id === $admin->id ? 1 : 0);
        $this->assertTrue($service->canAdjust($owner));
        $this->assertTrue($service->canAdjust($admin));
    }

    public function test_adjustment_is_limited_to_its_grn_intake_for_the_same_sku(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $firstPurchase = $this->approvedPurchase($owner, $product, $warehouse, 3, '30.0000');
        $firstReceipt = $this->receive($owner, $firstPurchase, 3, 0, 0);
        $secondPurchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '50.0000');
        $secondReceipt = $this->receive($owner, $secondPurchase, 2, 0, 0);
        $intakes = QcRecordedStockIntake::query()->orderBy('id')->get();
        $inventory = ProductInventory::query()->where('product_id', $product->id)->sole();

        app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intakes[0]->id, $owner, [
            'adjustment_quantity' => 2,
            'reason' => 'First GRN intake was over-recorded.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(1, $intakes[0]->fresh()->accepted_quantity);
        $this->assertSame(2, $intakes[1]->fresh()->accepted_quantity);
        $this->assertSame(3, $inventory->fresh()->qc_pending_quantity);
        $this->assertSame('130.0000', $inventory->fresh()->qc_pending_value);
        $this->assertSame(3, $firstReceipt->items->sole()->fresh()->accepted_quantity);
        $this->assertSame(2, $secondReceipt->items->sole()->fresh()->accepted_quantity);
    }

    public function test_qc_completion_after_safe_adjustment_releases_exactly_one_unit(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '100.0000');
        $receipt = $this->receive($owner, $purchase, 2, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intake->id, $owner, [
            'adjustment_quantity' => 1,
            'reason' => 'One unit was absent during physical recount.',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $inspection = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'ADJUST-RELEASE-001', $intake->id), $technician);
        $this->readyForCompletion($inspection, $technician);
        $this->uploadRequiredEvidence($inspection, $technician);
        app(QcInspectionService::class)->complete($inspection, $technician);

        $inventory = ProductInventory::query()->where('product_id', $product->id)->sole();
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame(1, $inventory->available_quantity);
        $this->assertSame(1, $intake->fresh()->accepted_quantity);
        $this->assertSame(2, $receipt->items->sole()->fresh()->accepted_quantity);
        $this->assertSame(1, QcRecordedStockIntakeRelease::query()->count());
        $this->assertSame(1, InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)->sole()->allocated_quantity);
    }

    public function test_order_qc_demand_forecast_is_unchanged_by_recorded_intake_adjustment(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '100.0000');
        $this->receive($owner, $purchase, 5, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $order = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $owner->employee->id,
            notes: null,
            items: [new OrderItemData($product->id, 2, '500.00')],
            idempotencyKey: (string) Str::uuid(),
            customerName: 'Forecast only',
        ), $owner);
        $before = app(QcOrderDemandService::class)->calculate($owner);

        app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intake->id, $owner, [
            'adjustment_quantity' => 2,
            'reason' => 'Physical intake recount.',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(['units_needed' => 2, 'orders_affected' => 1], $before);
        $this->assertSame($before, app(QcOrderDemandService::class)->calculate($owner));
        $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
        $this->assertSame(0, $order->items()->whereHas('reservations')->count());
    }

    public function test_concurrent_intake_adjustments_cannot_over_reduce_the_same_source(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $admin = $this->actor(EmployeeRole::Admin);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '45.0000');
        $this->receive($owner, $purchase, 5, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();

        $path = sys_get_temp_dir().'/tpz-qc-intake-adjustment-race-'.Str::uuid().'.sqlite';
        touch($path);
        $workers = [];
        $copy = null;

        try {
            $copy = new \PDO('sqlite:'.$path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $copy->exec('PRAGMA foreign_keys=OFF');
            foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $copy->exec($table->sql);
                foreach (DB::table($table->name)->get() as $record) {
                    $fields = array_keys((array) $record);
                    $statement = $copy->prepare('INSERT INTO "'.$table->name.'" ("'.implode('","', $fields).'") VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
                    $statement->execute(array_values((array) $record));
                }
            }
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL") as $index) {
                $copy->exec($index->sql);
            }
            $copy->exec('PRAGMA journal_mode=WAL');
            $statement = null;
            $copy = null;

            foreach ([$owner, $admin] as $slot => $actor) {
                $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/QcRecordedStockIntakeAdjustmentWorker.php'),
                    $path,
                    (string) $intake->id,
                    (string) $actor->id,
                    '3',
                    (string) Str::uuid(),
                    (string) $slot,
                ], base_path(), timeout: 45);
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
            foreach ($workers as $slot => $worker) {
                $this->assertFileExists($path.'.ready.'.$slot, 'Adjustment worker exited before lock readiness: '.$worker->getErrorOutput());
            }
            touch($path.'.go');

            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame(['adjusted', 'rejected'], $outcomes);

            $copy = new \PDO('sqlite:'.$path);
            $this->assertSame(2, (int) $copy->query('SELECT accepted_quantity FROM qc_recorded_stock_intakes WHERE id = '.$intake->id)->fetchColumn());
            $this->assertSame(2, (int) $copy->query('SELECT qc_pending_quantity FROM product_inventories WHERE product_id = '.$product->id)->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_recorded_stock_intake_adjustments')->fetchColumn());
            $this->assertSame(1, (int) $copy->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'renewed_qc_intake_adjustment'")->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = null;
            $copy = null;
            gc_collect_cycles();
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal', $path.'.go', $path.'.ready.0', $path.'.ready.1'] as $temporary) {
                for ($attempt = 0; is_file($temporary) && $attempt < 40; $attempt++) {
                    if (@unlink($temporary)) {
                        break;
                    }

                    usleep(50000);
                }
                if (is_file($temporary)) {
                    throw new \RuntimeException('Disposable intake adjustment race file could not be cleaned up.');
                }
            }
        }
    }

    public function test_draft_order_demand_is_aggregate_only_and_does_not_reserve_or_double_count_qc_pending(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '100.0000');
        $this->receive($owner, $purchase, 5, 0, 0);
        $before = $this->inventorySnapshot($product, $warehouse);
        $movementCount = StockMovement::query()->count();

        $order = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $owner->employee->id,
            notes: null,
            items: [new OrderItemData($product->id, 2, '500.00')],
            idempotencyKey: (string) Str::uuid(),
            customerName: 'Private Draft Customer',
        ), $owner);

        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame(5, $before['qc_pending_quantity']);
        $this->assertSame('500.0000', $before['qc_pending_value']);
        $this->assertSame(0, $before['available_quantity']);
        $this->assertSame($before, $this->inventorySnapshot($product, $warehouse));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertTrue(app(QcAuthorization::class)->allows($technician, QcPermission::View));
        $this->assertTrue(app(QcAuthorization::class)->allows($technician, QcPermission::Start));
        $this->assertFalse(app(QcAuthorization::class)->allows($technician, QcPermission::ViewDispatchQueue));
        $this->assertFalse(app(OrderAuthorization::class)->allows($technician, OrderPermission::View, $order));
        $this->assertSame(['units_needed' => 2, 'orders_affected' => 1], app(QcOrderDemandService::class)->calculate($owner));
        $queueDemand = app(QcOrderDemandService::class)->calculateForQcQueue($technician);
        $this->assertSame(['units_needed' => 2, 'orders_affected' => 1, 'by_product_location' => [$product->id => [$warehouse->id => 2]]], $queueDemand);
        $dashboard = Livewire::actingAs($technician)->test(QcProgress::class);
        $dashboard->assertDontSee($order->reference)->assertDontSee('Private Draft Customer');
        $dashboardStats = $this->qcProgressStats($technician);
        $this->assertSame($queueDemand['units_needed'], $dashboardStats['QC Needed for Orders']);
        $this->assertSame(0, $dashboardStats['Pending QC'], 'Forecast demand must not be counted as a pending inspection.');
        $this->assertFalse(app(QcOrderAssignmentService::class)->canChange($order));
        $this->assertValidation(fn () => app(QcOrderAssignmentService::class)->assign($order->items()->sole(), 1, $owner), 'order_item_id');
        $this->assertValidation(fn () => app(OrderService::class)->reserveDraft($order, $owner), 'items');

        $token = $technician->createToken('QC demand projection')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/recorded-renewed-stock')->assertOk()
            ->assertJsonPath('priority.needed_for_orders', 2)
            ->assertJsonPath('data.0.remaining_quantity', 5)
            ->assertJsonPath('data.0.order_priority_units', 2)
            ->assertJsonMissingPath('priority.orders_affected');
        $this->assertStringNotContainsString($order->reference, $response->getContent());
        $this->assertStringNotContainsString('Private Draft Customer', $response->getContent());

        Livewire::actingAs($technician)->test(RecordedRenewedStockQueue::class)
            ->assertSee('QC units needed for Orders')
            ->assertSee('2');

        $this->assertSame($before, $this->inventorySnapshot($product, $warehouse));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame(0, $order->items()->whereHas('reservations')->count());
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_sales_order_create_draft_adds_qc_pending_demand_to_the_technician_queue(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 1, '100.0000');
        $this->receive($owner, $purchase, 1, 0, 0);
        $inventoryBefore = $this->inventorySnapshot($product, $warehouse);
        $movementCount = StockMovement::query()->count();

        $component = Livewire::actingAs($owner)->test(CreateOrder::class)
            ->fillForm([
                'warehouse_id' => $warehouse->id,
                'marketplace_platform_id' => null,
                'external_order_number' => null,
                'order_date' => now()->toDateString(),
                'handled_by_employee_id' => $owner->employee->id,
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'selling_price' => '500.00',
                    'discount_total' => '0.00',
                    'vat_rate' => '0.0000',
                    'allocation_sources' => [],
                ]],
                'notes' => 'Draft awaiting QC; not a stock reservation.',
            ]);
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');
        $this->assertArrayHasKey($product->id, $productField->getSearchResults($product->sku));

        $component->mountAction(['name' => 'saveDraft', 'context' => ['schemaComponent' => 'content.form-actions']])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $order = Order::query()->sole();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame($inventoryBefore, $this->inventorySnapshot($product, $warehouse));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('qc_order_assignments', 0);
        $this->assertSame(['units_needed' => 1, 'orders_affected' => 1], app(QcOrderDemandService::class)->calculate($owner));
        $queueDemand = app(QcOrderDemandService::class)->calculateForQcQueue($technician);
        $this->assertSame(1, $queueDemand['units_needed']);
        $this->assertSame(1, $queueDemand['orders_affected']);
        $this->assertSame(1, $queueDemand['by_product_location'][$product->id][$warehouse->id]);

        $token = $technician->createToken('Draft QC queue priority')->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/recorded-renewed-stock')->assertOk()
            ->assertJsonPath('priority.needed_for_orders', 1)
            ->assertJsonPath('data.0.order_priority_units', 1)
            ->assertJsonMissingPath('data.0.order_reference');
    }

    public function test_order_priority_is_product_location_scoped_and_split_once_across_multiple_grn_rows(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $otherWarehouse = Warehouse::factory()->create();
        $productA = $this->product(ProductCondition::Renewed);
        $productB = $this->product(ProductCondition::Renewed);

        foreach ([1, 1] as $quantity) {
            $purchase = $this->approvedPurchase($owner, $productA, $warehouse, $quantity, '80.0000');
            $this->receive($owner, $purchase, $quantity, 0, 0);
        }
        $purchaseB = $this->approvedPurchase($owner, $productB, $warehouse, 1, '90.0000');
        $this->receive($owner, $purchaseB, 1, 0, 0);
        $purchaseAOtherLocation = $this->approvedPurchase($owner, $productA, $otherWarehouse, 1, '82.0000');
        $this->receive($owner, $purchaseAOtherLocation, 1, 0, 0);

        $order = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $owner->employee->id,
            notes: null,
            items: [new OrderItemData($productA->id, 3, '500.00')],
            idempotencyKey: (string) Str::uuid(),
            customerName: 'Private Priority Customer',
            customerPhone: '0501234567',
        ), $owner);

        $forecast = app(QcOrderDemandService::class)->calculateForQcQueue($technician);
        $this->assertSame(3, $forecast['units_needed']);
        $this->assertSame(3, $forecast['by_product_location'][$productA->id][$warehouse->id]);
        $this->assertArrayNotHasKey($otherWarehouse->id, $forecast['by_product_location'][$productA->id]);
        $this->assertArrayNotHasKey($productB->id, $forecast['by_product_location']);

        $token = $technician->createToken('QC intake priority projection')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/recorded-renewed-stock')->assertOk()
            ->assertJsonPath('priority.needed_for_orders', 3)
            ->assertJsonMissingPath('data.0.order_id')
            ->assertJsonMissingPath('data.0.order_reference')
            ->assertJsonMissingPath('data.0.order_item_id');
        $rows = $response->json('data');
        $aMainRows = collect($rows)->where('sku', $productA->sku)->where('warehouse', $warehouse->name);
        $aOtherLocationRows = collect($rows)->where('sku', $productA->sku)->where('warehouse', $otherWarehouse->name);
        $bMainRows = collect($rows)->where('sku', $productB->sku)->where('warehouse', $warehouse->name);
        $this->assertCount(2, $aMainRows);
        $this->assertSame(3, $aMainRows->sum('order_priority_units'));
        $this->assertSame([2, 1], $aMainRows->pluck('order_priority_units')->sortDesc()->values()->all());
        $this->assertCount(1, $aOtherLocationRows);
        $this->assertSame(0, $aOtherLocationRows->sum('order_priority_units'));
        $this->assertSame(0, $bMainRows->sum('order_priority_units'));
        $this->assertStringNotContainsString($order->reference, $response->getContent());
        $this->assertStringNotContainsString('Private Priority Customer', $response->getContent());
        $this->assertStringNotContainsString('0501234567', $response->getContent());

        $aMainIntakes = QcRecordedStockIntake::query()->where('product_id', $productA->id)->where('warehouse_id', $warehouse->id)->orderBy('id')->get();
        $aOtherLocationIntake = QcRecordedStockIntake::query()->where('product_id', $productA->id)->where('warehouse_id', $otherWarehouse->id)->sole();
        $bMainIntake = QcRecordedStockIntake::query()->where('product_id', $productB->id)->where('warehouse_id', $warehouse->id)->sole();
        Livewire::actingAs($technician)->test(RecordedRenewedStockQueue::class)
            ->assertOk()
            ->assertSee('Order Priority (SKU/location split)')
            ->assertSee('Order demand is aggregated by Product and Location')
            ->assertTableColumnStateSet('order_priority_units', 2, $aMainIntakes[0])
            ->assertTableColumnStateSet('order_priority_units', 1, $aMainIntakes[1])
            ->assertTableColumnStateSet('order_priority_units', 0, $aOtherLocationIntake)
            ->assertTableColumnStateSet('order_priority_units', 0, $bMainIntake);
    }

    public function test_one_compatible_ready_certificate_reduces_draft_forecast_without_assignment_or_stock_mutation(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '100.0000');
        $this->receive($owner, $purchase, 2, 0, 0);

        // A completed current certificate is existing, unclaimed supply. Owner manual QC
        // remains allowed; this does not consume the recorded intake's QC Pending units.
        $inspection = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'READY-SUPPLY-001'), $owner);
        $this->readyForCompletion($inspection, $owner);
        $this->uploadRequiredEvidence($inspection, $owner);
        $certificate = app(QcInspectionService::class)->complete($inspection, $owner);

        $order = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: null,
            externalOrderNumber: null,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $owner->employee->id,
            notes: null,
            items: [new OrderItemData($product->id, 2, '500.00')],
            idempotencyKey: (string) Str::uuid(),
        ), $owner);
        $beforeInventory = $this->inventorySnapshot($product, $warehouse);
        $beforeMovements = StockMovement::query()->count();
        $beforeAssignments = DB::table('qc_order_assignments')->count();
        $beforeReservations = $order->items()->withCount('reservations')->get()->sum('reservations_count');

        $forecast = app(QcOrderDemandService::class)->calculateForQcQueue($technician);

        $this->assertSame(1, $forecast['units_needed']);
        $this->assertSame(1, $forecast['by_product_location'][$product->id][$warehouse->id]);
        $this->assertSame(1, $forecast['orders_affected']);
        $this->assertCount(1, app(QcAvailableCertificateService::class)->currentUnclaimed());
        $this->assertFalse(app(QcOrderAssignmentService::class)->canChange($order));
        $this->assertValidation(fn () => app(QcOrderAssignmentService::class)->assign($order->items()->sole(), $certificate->id, $owner), 'order_item_id');
        $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
        $this->assertSame($beforeInventory, $this->inventorySnapshot($product, $warehouse));
        $this->assertSame(2, ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_quantity);
        $this->assertSame($beforeMovements, StockMovement::query()->count());
        $this->assertSame($beforeAssignments, DB::table('qc_order_assignments')->count());
        $this->assertSame($beforeReservations, $order->items()->withCount('reservations')->get()->sum('reservations_count'));
    }

    public function test_shared_technician_queue_is_qc_authorized_and_contains_no_financial_data(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $first = $this->approvedPurchase($owner, $product, $warehouse, 2, '80.0000');
        $firstReceipt = $this->receive($owner, $first, 2, 0, 0);
        $second = $this->approvedPurchase($owner, $product, $warehouse, 1, '90.0000');
        $secondReceipt = $this->receive($owner, $second, 1, 0, 0);

        $this->assertSame(2, QcRecordedStockIntake::query()->count());
        $this->assertNotSame($firstReceipt->items->sole()->id, $secondReceipt->items->sole()->id);
        $this->assertTrue(app(ProductAuthorization::class)->allows($owner, ProductPermission::View));
        $this->assertFalse(app(ProductAuthorization::class)->allows($technician, ProductPermission::View));
        $this->assertFalse(app(InventoryAuthorization::class)->allows($technician, InventoryPermission::View));
        $this->assertFalse(app(PurchaseAuthorization::class)->allows($technician, PurchasePermission::View));
        $this->assertTrue(app(QcAuthorization::class)->allows($technician, QcPermission::Start));

        $rows = app(QcRecordedRenewedStockIntakeService::class)->queue($technician);
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['remaining_quantity']);
        $this->assertSame(1, $rows[1]['remaining_quantity']);
        $this->assertSame($product->sku, $rows[0]['sku']);
        $this->assertSame($warehouse->name, $rows[0]['warehouse']);
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach (['inventory_unit_cost', 'average_cost', 'qc_pending_value', 'supplier', 'profit', 'inventory_value'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertTrue(app(QcRecordedRenewedStockIntakeService::class)->canAccess($owner));
        Livewire::actingAs($technician)->test(RecordedRenewedStockQueue::class)
            ->assertOk()->assertSee('Start QC')->assertSee($firstReceipt->reference)
            ->assertDontSee('Adjust Recorded QC Intake')->assertDontSee('100.0000');
        $this->actingAs($technician)->get(QcInspectionResource::getUrl('create'))->assertOk()
            ->assertSee('Manual / Unlinked QC')->assertDontSee('Order / Item (optional)');
    }

    public function test_start_claims_exactly_one_source_slot_and_manual_qc_is_unlinked(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 1, '120.0000');
        $receipt = $this->receive($owner, $purchase, 1, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $this->actingAs($technician)->get(QcInspectionResource::getUrl('create', ['recorded_stock_intake_id' => $intake->id]))
            ->assertOk()
            ->assertSee('Recorded Renewed Stock QC')
            ->assertSee($product->name)
            ->assertSee('Starting QC assigns one unit from this recorded intake for inspection.')
            ->assertSee('QC Pending stock is released only after successful QC completion.')
            ->assertSee('Start QC')
            ->assertSee('Cancel')
            ->assertDontSee('Create & create another')
            ->assertDontSee('Starting QC reserves one unit');
        $otherProduct = $this->product(ProductCondition::Renewed);
        $before = $this->inventorySnapshot($product, $warehouse);

        $this->assertValidation(fn () => app(QcInspectionService::class)->start($this->startData($otherProduct, $warehouse, 'TAMPERED-001', $intake->id), $technician), 'product_id');
        $this->assertSame(0, $intake->refresh()->started_quantity);

        $inspection = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'RECORDED-001', $intake->id), $technician);
        $this->assertSame($technician->id, $inspection->technician_user_id);
        $this->assertSame($intake->id, $inspection->recorded_stock_intake_id);
        $this->assertSame('recorded_renewed_stock', $inspection->qc_source);
        $this->assertSame(1, $intake->refresh()->started_quantity);
        $this->assertSame(0, $intake->accepted_quantity - $intake->started_quantity);
        $this->assertSame($before, $this->inventorySnapshot($product, $warehouse));
        $this->assertValidation(fn () => app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'RECORDED-002', $intake->id), $technician), 'recorded_stock_intake_id');

        $manual = app(QcInspectionService::class)->start($this->startData($otherProduct, $warehouse, 'MANUAL-001'), $technician);
        $this->assertNull($manual->recorded_stock_intake_id);
        $this->assertSame('manual_unlinked', $manual->qc_source);
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $otherProduct->id]);
        $this->assertSame(1, $receipt->items->sole()->accepted_quantity);
    }

    public function test_technician_cannot_start_manual_renewed_qc_while_matching_recorded_intake_is_pending(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $admin = $this->actor(EmployeeRole::Admin);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '75.0000');
        $this->receive($owner, $purchase, 2, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $inventoryBefore = $this->inventorySnapshot($product, $warehouse);
        $devicesBefore = DB::table('qc_devices')->count();
        $inspectionsBefore = QcInspection::query()->count();

        try {
            app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'BYPASS-TECH-001'), $technician);
            $this->fail('Technician manual QC must not bypass a matching recorded Renewed intake.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recorded_stock_intake_id', $exception->errors());
            $this->assertStringContainsString('Start QC from Recorded Renewed Stock Awaiting QC', $exception->errors()['recorded_stock_intake_id'][0]);
        }

        $this->assertSame(0, $intake->fresh()->started_quantity);
        $this->assertSame($inventoryBefore, $this->inventorySnapshot($product, $warehouse));
        $this->assertSame($devicesBefore, DB::table('qc_devices')->count());
        $this->assertSame($inspectionsBefore, QcInspection::query()->count());

        $ownerManual = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'BYPASS-OWNER-001'), $owner);
        $adminManual = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'BYPASS-ADMIN-001'), $admin);
        $this->assertNull($ownerManual->recorded_stock_intake_id);
        $this->assertNull($adminManual->recorded_stock_intake_id);
        $this->assertSame(0, $intake->fresh()->started_quantity);
        $this->assertSame($inventoryBefore, $this->inventorySnapshot($product, $warehouse));
    }

    public function test_technician_manual_renewed_qc_remains_available_without_matching_recorded_intake(): void
    {
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);

        $inspection = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'UNRECORDED-TECH-001'), $technician);

        $this->assertNull($inspection->recorded_stock_intake_id);
        $this->assertSame('manual_unlinked', $inspection->qc_source);
        $this->assertSame(0, QcRecordedStockIntake::query()->count());
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
    }

    public function test_two_technicians_cannot_claim_the_final_recorded_intake_slot_concurrently(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technicians = [$this->actor(EmployeeRole::Technician), $this->actor(EmployeeRole::Technician)];
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 1, '75.0000');
        $this->receive($owner, $purchase, 1, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();

        $path = sys_get_temp_dir().'/tpz-qc-intake-race-'.Str::uuid().'.sqlite';
        touch($path);
        $workers = [];
        $copy = null;

        try {
            $copy = new \PDO('sqlite:'.$path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $copy->exec('PRAGMA foreign_keys=OFF');
            foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
                $copy->exec($table->sql);
                foreach (DB::table($table->name)->get() as $record) {
                    $fields = array_keys((array) $record);
                    $statement = $copy->prepare('INSERT INTO "'.$table->name.'" ("'.implode('","', $fields).'") VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
                    $statement->execute(array_values((array) $record));
                }
            }
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL") as $index) {
                $copy->exec($index->sql);
            }
            $copy->exec('PRAGMA journal_mode=WAL');
            $copy = null;

            foreach ($technicians as $slot => $technician) {
                $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/QcRecordedStockIntakeWorker.php'),
                    $path,
                    (string) $intake->id,
                    (string) $technician->id,
                    'INTAKE-RACE-'.$slot,
                    (string) Str::uuid(),
                    (string) $slot,
                ], base_path(), timeout: 45);
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
            foreach ($workers as $slot => $worker) {
                $this->assertFileExists($path.'.ready.'.$slot, 'Worker '.$slot.' exited before intake claim readiness: '.$worker->getErrorOutput());
            }
            touch($path.'.go');

            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame(['rejected', 'started'], $outcomes);

            $copy = new \PDO('sqlite:'.$path);
            $this->assertSame(1, (int) $copy->query('SELECT started_quantity FROM qc_recorded_stock_intakes WHERE id = '.$intake->id)->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_inspections WHERE recorded_stock_intake_id = '.$intake->id)->fetchColumn());
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_devices WHERE serial IN (\'INTAKE-RACE-0\', \'INTAKE-RACE-1\')')->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = null;
            $copy = null;
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal', $path.'.go', $path.'.ready.0', $path.'.ready.1'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    public function test_partial_and_rework_qc_stay_pending_then_completion_releases_once_even_after_reinspection(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 1, '100.0000');
        $this->receive($owner, $purchase, 1, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $inspection = app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'RELEASE-001', $intake->id), $technician);
        $inventory = ProductInventory::query()->where('product_id', $product->id)->sole();

        app(QcInspectionService::class)->update($inspection, ['checks' => ['wifi' => ['result' => 'pass']]], $technician);
        $this->assertSame(1, $inventory->fresh()->qc_pending_quantity);
        $this->assertSame(0, $inventory->fresh()->available_quantity);
        app(QcInspectionService::class)->update($inspection, ['checks' => ['wifi' => ['result' => 'fail', 'notes' => 'Does not connect']]], $technician);
        $this->assertSame('rework_required', $inspection->fresh()->status->value);
        $this->assertSame(1, $inventory->fresh()->qc_pending_quantity);
        $this->assertSame(0, $inventory->fresh()->available_quantity);

        $this->readyForCompletion($inspection, $technician);
        $this->uploadRequiredEvidence($inspection, $technician);
        $certificate = app(QcInspectionService::class)->complete($inspection, $technician);
        $inventory->refresh();
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame(1, $inventory->available_quantity);
        $this->assertSame('100.0000', $inventory->average_cost);
        $releaseMovement = StockMovement::query()->where('movement_type', StockMovementType::RenewedQcRelease)->sole();
        $this->assertSame(1, $releaseMovement->available_delta);
        $this->assertSame(-1, $releaseMovement->bucketChanges()->sole()->quantity_delta);
        $this->assertSame('-100.0000', (string) $releaseMovement->bucketChanges()->sole()->value_delta);
        $this->assertSame(1, QcRecordedStockIntakeRelease::query()->count());
        $balance = InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)->sole();
        $this->assertSame(1, $balance->allocated_quantity);
        $this->assertSame($owner->employee->id, $balance->account->employee_id);
        $this->assertSame(1, $inspection->recordedStockIntake->receiptItem->allocationLines()->sole()->quantity);
        $this->assertSame($certificate->id, app(QcInspectionService::class)->complete($inspection, $technician)->id);
        $this->assertSame(1, $inventory->fresh()->available_quantity);
        $this->assertSame(1, QcRecordedStockIntakeRelease::query()->count());
        $this->assertSame(1, InventoryAllocationBalance::query()->where('product_inventory_id', $inventory->id)->sole()->allocated_quantity);

        $reinspection = app(QcInspectionService::class)->reopen($inspection, 'Owner-requested verification', $owner);
        $this->assertSame($intake->id, $reinspection->recorded_stock_intake_id);
        $this->readyForCompletion($reinspection, $owner);
        $this->uploadRequiredEvidence($reinspection, $owner);
        app(QcInspectionService::class)->complete($reinspection, $owner);
        $this->assertSame(1, ProductInventory::query()->where('product_id', $product->id)->sole()->available_quantity);
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::RenewedQcRelease)->count());
        $this->assertSame(1, QcRecordedStockIntakeRelease::query()->count());
    }

    public function test_legacy_receipt_correction_cannot_reduce_started_intake_demand(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 5, '70.0000');
        $receipt = $this->receive($owner, $purchase, 5, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'CORRECT-001', $intake->id), $technician);
        app(QcInspectionService::class)->start($this->startData($product, $warehouse, 'CORRECT-002', $intake->id), $technician);

        $this->assertSame(2, $intake->refresh()->started_quantity);
        $this->assertValidation(fn () => PurchaseReceiptCorrection::query()->create([
            'reference' => 'CORR-TEST-001',
            'purchase_id' => $purchase->id,
            'purchase_receipt_id' => $receipt->id,
            'purchase_receipt_item_id' => $receipt->items->sole()->id,
            'purchase_item_id' => $receipt->items->sole()->purchase_item_id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'original_received_quantity' => 5,
            'quantity_before' => 5,
            'corrected_quantity' => 1,
            'adjustment_quantity' => -4,
            'inventory_unit_cost' => '70.0000',
            'reason' => 'Legacy correction guard test',
            'performed_by_user_id' => $owner->id,
            'corrected_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'movement_group' => (string) Str::uuid(),
        ]), 'corrected_quantity');
        $this->assertSame(5, ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_quantity);
        $this->assertSame(0, PurchaseReceiptCorrection::query()->count());
    }

    public function test_technician_mobile_contract_lists_and_starts_recorded_intake_without_other_modules(): void
    {
        $owner = $this->actor(EmployeeRole::Owner);
        $technician = $this->actor(EmployeeRole::Technician);
        $warehouse = Warehouse::factory()->create();
        $product = $this->product(ProductCondition::Renewed);
        $purchase = $this->approvedPurchase($owner, $product, $warehouse, 2, '55.0000');
        $this->receive($owner, $purchase, 2, 0, 0);
        $intake = QcRecordedStockIntake::query()->sole();
        $token = $technician->createToken('QC intake contract')->plainTextToken;

        $manifest = $this->withToken($token)->getJson('/api/mobile/v1/workspace/manifest')->assertOk();
        $qc = collect($manifest->json('modules'))->firstWhere('key', 'qc');
        $this->assertSame('qc_focused', $manifest->json('workspace_mode'));
        $this->assertTrue($qc['capabilities']['recorded_stock_intake_queue']);
        $this->assertSame('/workspace/qc/recorded-renewed-stock', $qc['capabilities']['endpoints']['recorded_stock_intakes']);

        $this->withToken($token)->getJson('/api/mobile/v1/workspace/qc/recorded-renewed-stock')->assertOk()
            ->assertJsonPath('data.0.remaining_quantity', 2)
            ->assertJsonMissingPath('data.0.inventory_unit_cost');

        QcInspection::created(function (QcInspection $created) use ($intake, $owner): void {
            if ($created->recorded_stock_intake_id !== $intake->id) {
                return;
            }

            // Simulate an Owner adjustment after the mobile source model was read and
            // the Technician claimed a slot, but before the response computes remaining.
            app(QcRecordedStockIntakeAdjustmentService::class)->adjust($intake->id, $owner, [
                'adjustment_quantity' => 1,
                'reason' => 'Concurrent recount after a technician start.',
                'idempotency_key' => (string) Str::uuid(),
            ]);
        });

        $response = $this->withToken($token)->postJson('/api/mobile/v1/workspace/qc/recorded-renewed-stock/'.$intake->id.'/start', [
            'serial' => 'MOBILE-INTAKE-001',
            'features' => [],
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('remaining_quantity', 0);
        $this->assertSame(1, $intake->fresh()->accepted_quantity);
        $this->assertSame(1, $intake->fresh()->started_quantity);
        $this->assertSame(1, ProductInventory::query()->where('product_id', $product->id)->sole()->qc_pending_quantity);
        $inspection = QcInspection::query()->findOrFail($response->json('inspection_id'));
        $this->assertSame($technician->id, $inspection->technician_user_id);
        $this->assertSame($intake->id, $inspection->recorded_stock_intake_id);
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/products')->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/orders')->assertForbidden();
        $this->withToken($token)->getJson('/api/mobile/v1/workspace/inventory')->assertForbidden();
    }

    private function actor(EmployeeRole $role): User
    {
        $employee = Employee::factory()->role($role)->create();
        $email = 'qc-intake-'.Str::uuid().'@techpointzone.com';
        $employee->user()->update(['email' => $email]);
        $employee->update(['email' => $email]);

        return $employee->refresh()->user;
    }

    /** @return array<string, mixed> */
    private function qcProgressStats(User $actor): array
    {
        $component = Livewire::actingAs($actor)->test(QcProgress::class);
        $method = new \ReflectionMethod(QcProgress::class, 'getCachedStats');
        $method->setAccessible(true);

        return collect($method->invoke($component->instance()))
            ->mapWithKeys(fn (Stat $stat): array => [$stat->getLabel() => $stat->getValue()])
            ->all();
    }

    private function product(ProductCondition $condition): Product
    {
        return Product::factory()->create([
            'condition' => $condition,
            'model' => 'EliteBook 840 G6',
            'processor' => 'Intel Core i5 8th Gen',
            'ram' => '8GB',
            'storage' => '256GB',
            'touch_screen' => false,
        ]);
    }

    private function approvedPurchase(User $owner, Product $product, Warehouse $warehouse, int $quantity, string $cost): Purchase
    {
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $owner->employee->id,
            'assign_stock_by_default' => true,
        ]);
        ResponsibilityAssignmentBrand::query()->create([
            'assignment_id' => $assignment->id,
            'product_brand_id' => $product->brand_id,
        ]);
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            Supplier::factory()->create()->id,
            $warehouse->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, $quantity, $cost)],
            'INV-'.Str::random(8),
            now()->toDateString(),
        ), $owner);

        return app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Approved QC intake fixture.', true), $owner)->load('items');
    }

    private function receive(User $owner, Purchase $purchase, int $accepted, int $damaged, int $rejected)
    {
        return app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($purchase->items->sole()->id, $accepted, $damaged, $rejected)],
            now()->toDateTimeString(),
            (string) Str::uuid(),
        ), $owner);
    }

    private function startData(Product $product, Warehouse $warehouse, string $serial, ?int $intakeId = null): array
    {
        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'serial' => $serial,
            'features' => [],
            'idempotency_key' => (string) Str::uuid(),
            ...($intakeId === null ? [] : ['recorded_stock_intake_id' => $intakeId]),
        ];
    }

    private function readyForCompletion(QcInspection $inspection, User $actor): void
    {
        $checks = $inspection->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check) => [
            $check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => $check->check_key === 'temperature' ? 70 : 90] : []),
        ])->all();
        app(QcInspectionService::class)->update($inspection, [
            'checks' => $checks,
            'grade' => 'A',
            'final_configuration' => ['cpu' => 'Intel Core i5 8th Gen', 'ram_mb' => 8192, 'storage_gb' => 256, 'os' => 'Windows 11'],
            'public_remarks' => 'Renewed stock QC passed',
        ], $actor);
    }

    private function uploadRequiredEvidence(QcInspection $inspection, User $actor): void
    {
        foreach (app(QcEvidenceService::class)->requiredKinds($inspection) as $kind) {
            app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image($kind.'.jpg', 800, 600), $kind, true, $actor);
        }
    }

    private function inventorySnapshot(Product $product, Warehouse $warehouse): array
    {
        return ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->first()?->only([
            'available_quantity', 'reserved_quantity', 'damaged_quantity', 'qc_pending_quantity', 'qc_pending_value', 'average_cost',
        ]) ?? [];
    }

    private function assertValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
