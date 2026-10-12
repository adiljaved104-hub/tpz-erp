<?php

namespace Tests\Feature\Qc;

use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\StockTransfers\CancelStockTransfer;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\ReturnStockTransferToSource;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Enums\StockMovementType;
use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Filament\Resources\QcInspections\Widgets\QcProgress;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\QcCertificate;
use App\Models\QcDeviceClaim;
use App\Models\QcInspection;
use App\Models\QcStockTransferAssignment;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionReadService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcStockTransferAssignmentService;
use App\Services\Qc\QcStockTransferReadinessService;
use App\Services\Qc\QcTransferDemandService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class QcMarketplaceTransferTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    private User $owner;

    private Product $product;

    private Warehouse $source;

    private Warehouse $marketplace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $foundation = $this->responsibilityFoundation(30);
        $this->owner = $foundation['owner'];
        $this->product = $foundation['product']->forceFill(['condition' => ProductCondition::Renewed]);
        $this->product->save();
        $this->source = $foundation['inventory']->warehouse;
        $this->marketplace = Warehouse::factory()->create([
            'name' => 'Amazon FBA UAE',
            'code' => 'AMZ-FBA',
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $foundation['platform']->id,
            'status' => true,
        ]);
    }

    public function test_only_renewed_marketplace_fulfilment_lines_are_gated_and_failed_gate_posts_no_inventory(): void
    {
        $transfer = $this->transfer($this->product, 2);
        $before = $this->source->inventories()->first()->available_quantity;
        $statusEventsBefore = $transfer->statusEvents()->count();
        $readiness = app(QcStockTransferReadinessService::class)->forTransfer($transfer);
        $this->assertSame(2, $readiness['required']);
        $this->assertSame('pending_qc', $readiness['status']);
        try {
            app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $this->owner);
            $this->fail('Renewed FBA transfer must require all QC units.');
        } catch (InvalidStockTransferTransitionException $exception) {
            $this->assertStringContainsString('0 / 2 valid QC units assigned', $exception->getMessage());
        }

        $this->assertSame($before, $this->source->inventories()->first()->fresh()->available_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertSame(0, (int) $transfer->items()->sole()->dispatched_quantity);
        $this->assertSame($statusEventsBefore, $transfer->statusEvents()->count());

        $ordinaryDestination = Warehouse::factory()->create(['location_type' => InventoryLocationType::CompanyWarehouse, 'status' => true]);
        $normalTransfer = $this->transfer($this->product, 1, $ordinaryDestination);
        app(DispatchStockTransfer::class)->handle($normalTransfer, (string) Str::uuid(), $this->owner);

        $notRenewed = Product::factory()->create(['condition' => ProductCondition::OpenBox]);
        ProductInventory::factory()->create(['product_id' => $notRenewed->id, 'warehouse_id' => $this->source->id, 'available_quantity' => 2, 'reserved_quantity' => 0, 'average_cost' => '125.0000']);
        $notRenewedTransfer = $this->transfer($notRenewed, 1);
        $this->assertSame('not_required', app(QcStockTransferReadinessService::class)->forTransfer($notRenewedTransfer)['status']);
        app(DispatchStockTransfer::class)->handle($notRenewedTransfer, (string) Str::uuid(), $this->owner);
        $this->assertSame(StockTransferStatus::Dispatched, $notRenewedTransfer->fresh()->status);
    }

    public function test_qr_assignment_is_idempotent_readiness_is_exact_and_existing_transfer_engine_moves_stock(): void
    {
        $transfer = $this->transfer($this->product, 1);
        $certificate = $this->certify('FBA-QC-001');
        $code = app(QcDocumentService::class)->url($certificate);
        $service = app(QcStockTransferAssignmentService::class);
        $assignment = $service->assignScan($transfer, $code, $this->owner);
        $retry = $service->assignScan($transfer, $code, $this->owner);

        $this->assertSame($assignment->id, $retry->id);
        $this->assertDatabaseCount('qc_stock_transfer_assignments', 1);
        $this->assertDatabaseCount('qc_device_claims', 1);
        $this->assertSame(['status' => 'ready', 'required' => 1, 'assigned' => 1, 'valid' => 1, 'remaining' => 0], array_intersect_key(app(QcStockTransferReadinessService::class)->forTransfer($transfer), array_flip(['status', 'required', 'assigned', 'valid', 'remaining'])));
        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);

        $dispatched = app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $this->owner);
        $this->assertSame(StockTransferStatus::Dispatched, $dispatched->status);
        $this->assertSame(StockMovementType::TransferDispatch, StockMovement::query()->sole()->movement_type);
        $this->assertSame($assignment->id, $dispatched->qcAssignments()->active()->sole()->id);

        $received = app(ReceiveStockTransfer::class)->handle($dispatched, (string) Str::uuid(), $this->owner);
        $this->assertSame(StockTransferStatus::Received, $received->status);
        $this->assertSame(StockMovementType::TransferReceipt, StockMovement::query()->latest('id')->value('movement_type'));
        $this->assertNotNull(QcDeviceClaim::query()->active()->where('active_device_id', $certificate->device_id)->first());
        $this->assertSame($certificate->snapshot['serial'], $received->qcAssignments()->sole()->certificate->snapshot['serial']);
    }

    public function test_scan_never_dispatches_and_optional_physical_serial_must_match(): void
    {
        $transfer = $this->transfer($this->product, 1);
        $certificate = $this->certify('PHYSICAL-001');

        $this->reject(fn () => app(QcStockTransferAssignmentService::class)->assignScan($transfer, app(QcDocumentService::class)->url($certificate), $this->owner, 'DIFFERENT-SERIAL'), 'physical_serial');
        $this->assertDatabaseCount('qc_stock_transfer_assignments', 0);
        $assignment = app(QcStockTransferAssignmentService::class)->assignScan($transfer, app(QcDocumentService::class)->url($certificate), $this->owner, 'physical-001');
        $this->assertSame($certificate->device_id, $assignment->qc_device_id);
        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_product_source_certificate_version_and_active_reinspection_are_validated(): void
    {
        $transfer = $this->transfer($this->product, 2);
        $wrongProduct = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        $wrongProductCertificate = $this->certify('WRONG-PRODUCT', ['product_id' => $wrongProduct->id]);
        $this->reject(fn () => $this->assign($transfer, $wrongProductCertificate), 'code');

        $wrongLocation = Warehouse::factory()->create(['status' => true]);
        $wrongWarehouseCertificate = $this->certify('WRONG-WAREHOUSE', ['warehouse_id' => $wrongLocation->id]);
        $this->reject(fn () => $this->assign($transfer, $wrongWarehouseCertificate), 'code');

        $stale = $this->certify('STALE-CERT');
        $reinspection = app(QcInspectionService::class)->reopen($stale->inspection, 'Recheck before FBA transfer', $this->owner);
        $this->reject(fn () => $this->assign($transfer, $stale), 'certificate_id');
        $current = $this->finish($reinspection);
        $this->reject(fn () => $this->assign($transfer, $stale), 'certificate_id');
        $this->assertSame(0, $transfer->qcAssignments()->count());
        $this->assertSame(2, $current->version);
    }

    public function test_global_claim_prevents_transfer_duplicates_and_cross_workflow_order_assignment(): void
    {
        $transfer = $this->transfer($this->product, 1);
        $certificate = $this->certify('GLOBAL-CLAIM-001');
        $assignment = $this->assign($transfer, $certificate);
        $this->assertDatabaseHas('qc_device_claims', ['qc_device_id' => $certificate->device_id, 'active_device_id' => $certificate->device_id, 'qc_stock_transfer_assignment_id' => $assignment->id]);

        $secondTransfer = $this->transfer($this->product, 1);
        $this->reject(fn () => $this->assign($secondTransfer, $certificate), 'code');
        $orderItem = $this->orderItem();
        $this->reject(fn () => app(QcOrderAssignmentService::class)->assign($orderItem, $certificate->id, $this->owner), 'certificate_id');
        $this->assertDatabaseCount('qc_stock_transfer_assignments', 1);
        $this->assertDatabaseCount('qc_order_assignments', 0);
    }

    public function test_order_claim_prevents_marketplace_transfer_assignment_and_global_unique_index_is_database_enforced(): void
    {
        $certificate = $this->certify('ORDER-CLAIM-001');
        $orderItem = $this->orderItem();
        $orderAssignment = app(QcOrderAssignmentService::class)->assign($orderItem, $certificate->id, $this->owner);
        $transfer = $this->transfer($this->product, 1);
        $this->reject(fn () => $this->assign($transfer, $certificate), 'code');

        $this->assertDatabaseHas('qc_device_claims', ['qc_device_id' => $certificate->device_id, 'active_device_id' => $certificate->device_id, 'qc_order_assignment_id' => $orderAssignment->id]);
        $this->expectException(QueryException::class);
        DB::table('qc_device_claims')->insert([
            'qc_device_id' => $certificate->device_id,
            'active_device_id' => $certificate->device_id,
            'qc_order_assignment_id' => null,
            'qc_stock_transfer_assignment_id' => null,
            'claimed_by_user_id' => $this->owner->id,
            'claimed_at' => now(),
        ]);
    }

    public function test_order_and_transfer_assignment_race_cannot_claim_the_same_device(): void
    {
        $certificate = $this->certify('CROSS-WORKFLOW-RACE-001');
        $orderItem = $this->orderItem();
        $transfer = $this->transfer($this->product, 1);
        $path = sys_get_temp_dir().'/tpz-qc-assignment-race-'.str()->uuid().'.sqlite';
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

            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/QcOrderAssignmentWorker.php'), $path, (string) $orderItem->id, (string) $certificate->id, (string) $this->owner->id, '0'], base_path(), timeout: 45);
            $workers[] = new Process([PHP_BINARY, base_path('tests/Support/QcStockTransferAssignmentWorker.php'), $path, (string) $transfer->id, (string) $certificate->id, (string) $this->owner->id, '1'], base_path(), timeout: 45);
            foreach ($workers as $worker) {
                $worker->start();
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
                $this->assertFileExists($path.'.ready.'.$slot, 'Worker '.$slot.' exited '.$worker->getExitCode().' before readiness; diagnostics: '.$worker->getErrorOutput());
            }
            touch($path.'.go');

            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame(['assigned', 'rejected'], $outcomes);

            $copy = new \PDO('sqlite:'.$path);
            $this->assertSame(1, (int) $copy->query('SELECT COUNT(*) FROM qc_device_claims WHERE active_device_id IS NOT NULL AND released_at IS NULL')->fetchColumn());
            $activeOrderAssignments = (int) $copy->query('SELECT COUNT(*) FROM qc_order_assignments WHERE released_at IS NULL AND active_device_id IS NOT NULL')->fetchColumn();
            $activeTransferAssignments = (int) $copy->query('SELECT COUNT(*) FROM qc_stock_transfer_assignments WHERE released_at IS NULL AND active_device_id IS NOT NULL')->fetchColumn();
            $this->assertSame(1, $activeOrderAssignments + $activeTransferAssignments);
            $claim = $copy->query('SELECT qc_order_assignment_id, qc_stock_transfer_assignment_id FROM qc_device_claims WHERE active_device_id IS NOT NULL')->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame($activeOrderAssignments === 1 ? 1 : 0, (int) ($claim['qc_order_assignment_id'] !== null));
            $this->assertSame($activeTransferAssignments === 1 ? 1 : 0, (int) ($claim['qc_stock_transfer_assignment_id'] !== null));
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

    public function test_claim_migration_preflights_legacy_order_claims_before_ddl_and_backfills_them(): void
    {
        $certificate = $this->certify('BACKFILL-ORDER-CLAIM');
        $assignment = app(QcOrderAssignmentService::class)->assign($this->orderItem(), $certificate->id, $this->owner);

        Schema::dropIfExists('qc_device_claims');
        Schema::dropIfExists('qc_stock_transfer_assignments');

        $conflictingCertificate = $this->certify('BACKFILL-CONFLICT-UNIT');
        DB::table('qc_order_assignments')->where('id', $assignment->id)->update([
            'active_device_id' => $conflictingCertificate->device_id,
        ]);

        $migration = require database_path('migrations/2026_10_11_090000_create_qc_stock_transfer_assignments_and_device_claims.php');
        try {
            $migration->up();
            $this->fail('Unsafe legacy active Order claims must stop migration before DDL.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Active QC Order assignments cannot be safely represented in the global device claim ledger.', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasTable('qc_device_claims'));
        $this->assertFalse(Schema::hasTable('qc_stock_transfer_assignments'));

        DB::table('qc_order_assignments')->where('id', $assignment->id)->update([
            'active_device_id' => $assignment->qc_device_id,
        ]);
        $migration->up();

        $this->assertDatabaseHas('qc_device_claims', [
            'qc_device_id' => $assignment->qc_device_id,
            'active_device_id' => $assignment->qc_device_id,
            'qc_order_assignment_id' => $assignment->id,
        ]);
    }

    public function test_assignment_quantity_cap_and_auto_or_manual_line_matching(): void
    {
        $secondProduct = Product::factory()->create(['condition' => ProductCondition::Renewed]);
        ProductInventory::factory()->create(['product_id' => $secondProduct->id, 'warehouse_id' => $this->source->id, 'available_quantity' => 2, 'reserved_quantity' => 0]);
        $transfer = $this->transfer($this->product, 1, $this->marketplace, [$this->product, $secondProduct]);
        $first = $this->certify('MATCH-SECOND', ['product_id' => $secondProduct->id]);
        $lines = $transfer->items()->orderBy('id')->get();
        $assignment = app(QcStockTransferAssignmentService::class)->assignScan($transfer, app(QcDocumentService::class)->url($first), $this->owner);
        $this->assertSame($lines[1]->id, $assignment->stock_transfer_item_id);

        $second = $this->certify('MATCH-FIRST');
        $chosen = app(QcStockTransferAssignmentService::class)->assignScan($transfer, app(QcDocumentService::class)->url($second), $this->owner, stockTransferItemId: $lines[0]->id);
        $this->assertSame($lines[0]->id, $chosen->stock_transfer_item_id);
        $overflow = $this->certify('MATCH-OVERFLOW');
        $this->reject(fn () => $this->assign($transfer, $overflow, $lines[0]->id), 'stock_transfer_item_id');
        $this->assertSame(2, $transfer->qcAssignments()->active()->count());
    }

    public function test_draft_release_and_cancel_release_claims_but_preserve_assignment_history(): void
    {
        $certificate = $this->certify('RELEASE-001');
        $transfer = $this->transfer($this->product, 1);
        $assignment = $this->assign($transfer, $certificate);
        $released = app(QcStockTransferAssignmentService::class)->release($transfer, $assignment, 'Wrong physical unit', $this->owner);
        $this->assertNotNull($released->released_at);
        $this->assertNull($released->active_device_id);
        $this->assertNotNull(QcDeviceClaim::query()->where('qc_stock_transfer_assignment_id', $assignment->id)->whereNotNull('released_at')->first());
        $this->assertDatabaseCount('qc_stock_transfer_assignments', 1);
        $this->assertDatabaseCount('stock_movements', 0);

        $replacement = $this->transfer($this->product, 1);
        $this->assign($replacement, $certificate);
        $cancelled = $this->transfer($this->product, 1);
        $cancelAssignment = $this->assign($cancelled, $this->certify('CANCEL-CLAIM'));
        app(CancelStockTransfer::class)->handle($cancelled, 'Draft no longer needed', (string) Str::uuid(), $this->owner);
        $this->assertNotNull($cancelAssignment->fresh()->released_at);
        $this->assertSame(StockTransferStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(0, $cancelled->fresh()->qcAssignments()->active()->count());
    }

    public function test_received_units_keep_global_claim_until_authoritative_return_to_source(): void
    {
        $certificate = $this->certify('RECEIVED-CLAIM-001');
        $transfer = $this->transfer($this->product, 1);
        $this->assign($transfer, $certificate);
        $dispatched = app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $this->owner);
        $received = app(ReceiveStockTransfer::class)->handle($dispatched, (string) Str::uuid(), $this->owner);
        $this->assertNotNull(QcDeviceClaim::query()->active()->where('active_device_id', $certificate->device_id)->first());
        $this->assertNull($received->qcAssignments()->sole()->released_at);

        $returnCertificate = $this->certify('RETURN-CLAIM-002');
        $returnTransfer = $this->transfer($this->product, 1);
        $returnAssignment = $this->assign($returnTransfer, $returnCertificate);
        $inTransit = app(DispatchStockTransfer::class)->handle($returnTransfer, (string) Str::uuid(), $this->owner);
        $returned = app(ReturnStockTransferToSource::class)->handle($inTransit, 'Marketplace shipment returned to source', (string) Str::uuid(), $this->owner);
        $this->assertSame(StockTransferStatus::Returned, $returned->status);
        $this->assertNotNull($returnAssignment->fresh()->released_at);
        $this->assertNull(QcDeviceClaim::query()->active()->where('active_device_id', $returnCertificate->device_id)->first());
        $next = $this->transfer($this->product, 1);
        $this->assign($next, $returnCertificate);
    }

    public function test_dispatched_assignment_cannot_be_released_and_return_is_the_only_claim_release_path(): void
    {
        $certificate = $this->certify('NO-ADHOC-RELEASE');
        $transfer = $this->transfer($this->product, 1);
        $assignment = $this->assign($transfer, $certificate);
        app(DispatchStockTransfer::class)->handle($transfer, (string) Str::uuid(), $this->owner);
        $this->reject(fn () => app(QcStockTransferAssignmentService::class)->release($transfer, $assignment, 'Wrong', $this->owner), 'assignment_id');
        $this->assertNull($assignment->fresh()->released_at);
        $this->assertNotNull(QcDeviceClaim::query()->active()->where('active_device_id', $certificate->device_id)->first());
    }

    public function test_mobile_qc_transfer_api_lists_readiness_and_verified_scan_does_not_dispatch(): void
    {
        $transfer = $this->transfer($this->product, 1);
        $certificate = $this->certify('API-QC-001');
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/mobile/v1/workspace/qc/transfers?page=1&per_page=10')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.reference', $transfer->reference)
            ->assertJsonPath('data.0.readiness.status', 'pending_qc');
        $this->getJson("/api/mobile/v1/workspace/qc/transfers/{$transfer->id}")
            ->assertOk()->assertJsonPath('data.readiness.status', 'pending_qc');
        $this->postJson("/api/mobile/v1/workspace/qc/transfers/{$transfer->id}/verified-scan", [
            'code' => app(QcDocumentService::class)->url($certificate),
            'physical_serial' => $certificate->snapshot['serial'],
        ])->assertCreated()->assertJsonPath('data.verification_mode', 'qc_label_and_serial')
            ->assertJsonPath('data.readiness.status', 'ready');
        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
        $show = $this->getJson("/api/mobile/v1/workspace/qc/transfers/{$transfer->id}")->assertOk();
        $assignmentId = $show->json('data.assignments.0.assignment_id');
        $this->assertNotEmpty($assignmentId);
        $this->postJson("/api/mobile/v1/workspace/qc/transfers/{$transfer->id}/assignments/{$assignmentId}/release", ['reason' => 'Wrong physical unit'])
            ->assertOk()->assertJsonPath('data.assignment_state', 'released')->assertJsonPath('data.readiness.status', 'pending_qc');
        $this->assertSame(StockTransferStatus::Draft, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_transfer_demand_is_separate_from_pending_qc_and_free_certificates_are_matched_once(): void
    {
        $first = $this->transfer($this->product, 1);
        $this->assertSame(0, app(QcInspectionReadService::class)->counters($this->owner)['pending']);
        $this->assertSame(['units_needed' => 1, 'transfers_affected' => 1, 'marketplace_transfers' => 1], app(QcTransferDemandService::class)->summary($this->owner));

        $inspectionCount = QcInspection::query()->count();
        $pendingTransferStats = $this->qcProgressStats($this->owner);
        $this->assertSame(0, $pendingTransferStats['Pending QC']);
        $this->assertSame(1, $pendingTransferStats['QC Needed for Transfers']);
        $this->assertSame(1, $pendingTransferStats['Marketplace Transfers']);
        $this->assertSame($inspectionCount, QcInspection::query()->count());

        $certificate = $this->certify('DEMAND-FREE-001');
        $this->assertSame(0, app(QcInspectionReadService::class)->counters($this->owner)['pending']);
        $this->assertSame(['units_needed' => 0, 'transfers_affected' => 0, 'marketplace_transfers' => 1], app(QcTransferDemandService::class)->summary($this->owner));

        $unassignedTransferStats = $this->qcProgressStats($this->owner);
        $this->assertSame(0, $unassignedTransferStats['Pending QC']);
        $this->assertSame(0, $unassignedTransferStats['QC Needed for Transfers']);
        $this->assertSame(1, $unassignedTransferStats['Marketplace Transfers']);
        $this->assertSame('pending_qc', app(QcStockTransferReadinessService::class)->forTransfer($first)['status']);
        $this->assertSame(StockTransferStatus::Draft, $first->fresh()->status);

        $second = $this->transfer($this->product, 1);
        $this->assertSame(['units_needed' => 1, 'transfers_affected' => 1, 'marketplace_transfers' => 2], app(QcTransferDemandService::class)->summary($this->owner));

        $this->assign($first, $certificate);
        $this->assertSame(['units_needed' => 1, 'transfers_affected' => 1, 'marketplace_transfers' => 1], app(QcTransferDemandService::class)->summary($this->owner));
        $this->assertSame(StockTransferStatus::Draft, $first->fresh()->status);
        $this->assertSame(StockTransferStatus::Draft, $second->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_web_qc_demand_cards_do_not_expose_order_or_transfer_counts_without_capability(): void
    {
        $this->orderItem();
        $this->transfer($this->product, 1);
        $this->assertSame(1, app(QcOrderDemandService::class)->calculate($this->owner)['units_needed']);
        $this->assertSame(['units_needed' => 1, 'transfers_affected' => 1, 'marketplace_transfers' => 1], app(QcTransferDemandService::class)->summary($this->owner));

        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            QcPermission::View->value,
            EmployeePermissionEffect::Allow,
            'View QC inspections only',
            $this->owner,
        );

        $stats = $this->qcProgressStats($staff);

        $this->assertSame(0, $stats['QC Needed for Orders']);
        $this->assertSame(0, $stats['QC Needed for Transfers']);
        $this->assertSame(0, $stats['Marketplace Transfers']);
    }

    public function test_order_claimed_certificate_is_not_forecast_as_marketplace_transfer_supply(): void
    {
        $certificate = $this->certify('DEMAND-ORDER-CLAIM');
        app(QcOrderAssignmentService::class)->assign($this->orderItem(), $certificate->id, $this->owner);
        $transfer = $this->transfer($this->product, 1);

        $this->assertSame(['units_needed' => 1, 'transfers_affected' => 1, 'marketplace_transfers' => 1], app(QcTransferDemandService::class)->summary($this->owner));
        $this->assertSame(1, QcDeviceClaim::query()->active()->where('active_device_id', $certificate->device_id)->count());
    }

    public function test_qc_home_exposes_distinct_order_transfer_and_pending_inspection_counts(): void
    {
        $this->transfer($this->product, 1);
        $email = 'qc-mobile-'.$this->owner->id.'@techpointzone.com';
        $this->owner->update(['email' => $email]);
        $this->owner->employee->update(['email' => $email]);
        $this->withToken($this->owner->createToken('QC Dispatch Test')->plainTextToken);

        $response = $this->getJson('/api/mobile/v1/workspace/qc');
        $response->assertOk()
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.qc_order_demand.units_needed', 0)
            ->assertJsonPath('data.qc_transfer_demand.units_needed', 1)
            ->assertJsonPath('data.qc_transfer_demand.transfers_affected', 1)
            ->assertJsonPath('data.marketplace_transfers', 1)
            ->assertJsonPath('data.capabilities.marketplace_transfers', true)
            ->assertJsonPath('data.capabilities.transfer_assignment', true)
            ->assertJsonPath('data.capabilities.transfer_release', true);
    }

    public function test_stock_transfer_qc_permission_is_narrow_and_staff_needs_explicit_action_access(): void
    {
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $transfer = $this->transfer($this->product, 1);
        $certificate = $this->certify('PERMISSION-QC-001');
        $this->assertFalse(app(QcAuthorization::class)->allows($staff, QcPermission::AssignMarketplaceTransferDevice));
        $this->assertFalse(app(QcAuthorization::class)->allows($staff, QcPermission::ReleaseMarketplaceTransferDevice));

        $this->expectException(AuthorizationException::class);
        $this->assign($transfer, $certificate, actor: $staff);
    }

    private function transfer(Product $product, int $quantity, ?Warehouse $destination = null, ?array $products = null): StockTransfer
    {
        $destination ??= $this->marketplace;
        $products ??= [$product];
        $items = array_map(fn (Product $item): StockTransferItemData => new StockTransferItemData($item->id, $quantity), $products);

        return app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $this->source->id,
            $destination->id,
            today()->toDateString(),
            $items,
            (string) Str::uuid(),
        ), $this->owner);
    }

    private function orderItem(): OrderItem
    {
        $order = app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData(
            $this->source->id,
            null,
            null,
            today()->toDateString(),
            $this->owner->employee->id,
            null,
            [new OrderItemData($this->product->id, 1, '500.00')],
            (string) Str::uuid(),
        ), $this->owner);

        return $order->items()->sole();
    }

    private function assign(StockTransfer $transfer, QcCertificate $certificate, ?int $itemId = null, ?User $actor = null): QcStockTransferAssignment
    {
        return app(QcStockTransferAssignmentService::class)->assignScan($transfer, app(QcDocumentService::class)->url($certificate), $actor ?? $this->owner, stockTransferItemId: $itemId);
    }

    private function certify(string $serial, array $changes = []): QcCertificate
    {
        $inspection = app(QcInspectionService::class)->start($changes + [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->source->id,
            'serial' => $serial,
            'features' => [],
            'idempotency_key' => (string) Str::uuid(),
        ], $this->owner);

        return $this->finish($inspection);
    }

    private function finish(QcInspection $inspection): QcCertificate
    {
        $service = app(QcInspectionService::class);
        $configuration = ['cpu' => 'Intel Core i5', 'ram_mb' => 8192, 'storage_gb' => 256, 'os' => 'Windows 11'];
        $service->update($inspection, ['grade' => 'A', 'final_configuration' => $configuration], $this->owner);
        $checks = $inspection->checks()->get()->where('applicable', true)->mapWithKeys(fn ($check): array => [$check->check_key => ['result' => 'pass'] + ($check->definition['measurement'] ? ['measurement' => $check->check_key === 'temperature' ? 70 : 90] : [])])->all();
        $service->update($inspection, ['checks' => $checks], $this->owner);
        foreach (['serial', 'physical', 'display', 'system'] as $kind) {
            app(QcEvidenceService::class)->upload($inspection, UploadedFile::fake()->image($kind.'.jpg', 800, 600), $kind, true, $this->owner);
        }

        return $service->complete($inspection->fresh(), $this->owner);
    }

    private function reject(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors(), json_encode($exception->errors()));
        }
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
}
