<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Returns\CancelCustomerReturn;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\CustomerReturns\Pages\CreateCustomerReturn as CreateCustomerReturnPage;
use App\Filament\Resources\CustomerReturns\Pages\ListCustomerReturns;
use App\Filament\Resources\CustomerReturns\Pages\ViewCustomerReturn as ViewCustomerReturnPage;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnFinding;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\SafetClaim;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Inventory\DamagedItemsQueueService;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\ReferenceSequenceService;
use App\Services\Returns\CustomerReturnEvidenceService;
use App\Services\Returns\CustomerReturnQuarantineService;
use App\Services\Returns\CustomerReturnUnifiedWorkflowService;
use App\Services\Returns\QcPendingQueueService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_company_return_receives_to_qc_then_split_classifies_once_with_original_cost(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(5, 3, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 3);
        $this->assertMatchesRegularExpression('/^RTN-\d{4}-\d{6}$/', $return->reference);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $inventory->refresh();
        $this->assertSame(CustomerReturnStatus::QcPending, $return->refresh()->status);
        $this->assertNotNull($return->received_at);
        $this->assertSame(2, $inventory->available_quantity);
        $this->assertSame(0, $inventory->reserved_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertSame(3, $inventory->qc_pending_quantity);
        $this->assertSame('300.0000', $inventory->qc_pending_value);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertDatabaseCount('safet_claims', 0);
        $receipt = StockMovement::query()->where('movement_type', StockMovementType::CustomerReturnCompanyReceipt)->sole();
        $this->assertSame(0, $receipt->available_delta);
        $this->assertSame(3, $receipt->bucketChanges()->sole()->quantity_delta);

        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(2, 1, (string) Str::uuid()), $owner);
        $inventory->refresh();
        $return->refresh();
        $this->assertSame(4, $inventory->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame('100.0000', $inventory->average_cost);
        $this->assertSame(CustomerReturnStatus::Completed, $return->status);
        $this->assertDatabaseCount('customer_return_inspections', 2);
        $this->assertDatabaseHas('activity_logs', ['event' => 'customer_return.completed', 'subject_id' => $return->id]);
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee('QC Results & Evidence')->assertSee('Mixed')
            ->assertSee('Not separately recorded')->assertSee('Evidence Pending');
    }

    public function test_weighted_average_uses_original_fulfilment_cost_and_does_not_change_cogs(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(10, 2, '200.0000');
        $cogs = $order->fulfillment->items->first()->cogs_total;
        $inventory->forceFill(['average_cost' => '300.0000'])->save();
        $return = $this->createReturn($owner, $order, $warehouse, 2);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(2, 0, (string) Str::uuid()), $owner);
        $this->assertSame('280.0000', $inventory->refresh()->average_cost);
        $this->assertSame($cogs, $order->fulfillment->items->first()->fresh()->cogs_total);
    }

    public function test_partial_returns_cannot_cumulatively_exceed_fulfilled_quantity(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(5, 3, '100.0000');
        $this->createReturn($owner, $order, $warehouse, 2);
        $this->createReturn($owner, $order, $warehouse, 1);
        $this->expectException(CustomerReturnException::class);
        $this->createReturn($owner, $order, $warehouse, 1);
    }

    public function test_duplicate_receive_and_inspection_are_blocked_without_inventory_change(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        try {
            app(ReceiveCustomerReturn::class)->handle($return->refresh(), $owner);
            $this->fail();
        } catch (CustomerReturnException) {
        }
        $key = (string) Str::uuid();
        app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(1, 0, $key), $owner);
        try {
            app(InspectCustomerReturnItem::class)->handle($return->items->first(), new InspectCustomerReturnItemData(1, 0, $key), $owner);
            $this->fail();
        } catch (CustomerReturnException) {
        }
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 1);
    }

    public function test_create_receive_and_qc_reserve_all_references_before_business_transactions(): void
    {
        [$owner, , $warehouse, , $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $probe = new class extends ReferenceSequenceService
        {
            public array $levels = [];

            public function nextCustomerReturnReference(?int $year = null): string
            {
                $this->levels['return'][] = DB::transactionLevel();

                return 'RTN-2026-990001';
            }

            public function nextStockMovementReference(): string
            {
                $this->levels['movement'][] = DB::transactionLevel();

                return 'SM-'.str_pad((string) (990000 + count($this->levels['movement'])), 6, '0', STR_PAD_LEFT);
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->levels['damage'][] = DB::transactionLevel();

                return 'DMG-2026-990001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambient = DB::transactionLevel();
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $owner);

        $this->assertSame([$ambient], $probe->levels['return']);
        $this->assertSame([$ambient, $ambient], $probe->levels['movement']);
        $this->assertSame([$ambient], $probe->levels['damage']);
    }

    public function test_failed_receive_rolls_back_status_qc_bucket_inventory_and_movements(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        $inventory->refresh();
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost', 'qc_pending_quantity', 'qc_pending_value']);
        $movementCount = StockMovement::query()->count();
        $bucketCount = DB::table('stock_movement_bucket_changes')->count();
        $this->app->instance(InventoryBalanceService::class, new class extends InventoryBalanceService
        {
            public function lockOrCreate(int $productId, int $warehouseId): ProductInventory
            {
                throw new CustomerReturnException('Simulated receive failure.');
            }
        });

        try {
            app(ReceiveCustomerReturn::class)->handle($return, $owner);
            $this->fail('Simulated Receive failure was not propagated.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Simulated receive failure.', $exception->getMessage());
        }

        $this->assertSame(CustomerReturnStatus::Draft, $return->refresh()->status);
        $this->assertNull($return->received_at);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($bucketCount, DB::table('stock_movement_bucket_changes')->count());
        $this->assertDatabaseCount('damaged_stock_events', 0);
        $this->assertDatabaseCount('safet_claims', 0);
    }

    public function test_receiving_can_create_destination_inventory_and_cancellation_stops_after_posting(): void
    {
        [$owner,$product,$warehouse,$inventory,$order] = $this->fulfilledOrder(3, 1, '125.5000');
        $destination = Warehouse::factory()->create(['status' => true]);
        $return = $this->createReturn($owner, $order, $destination, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $created = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $destination->id)->sole();
        $this->assertSame(1, $created->qc_pending_quantity);
        $this->assertSame(0, $created->available_quantity);
        $this->assertNull($created->average_cost);
        $this->expectException(CustomerReturnException::class);
        app(CancelCustomerReturn::class)->handle($return->refresh(), 'Too late', $owner);
    }

    public function test_direct_company_return_still_requires_a_receiving_location(): void
    {
        [$owner, , , , $order] = $this->fulfilledOrder(3, 1, '125.5000');

        $this->expectException(CustomerReturnException::class);
        app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
    }

    public function test_permission_defaults_match_phase_two_decision(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin, EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $user = User::factory()->create();
            Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);
            $user->refresh();
            $auth = app(CustomerReturnAuthorization::class);
            $this->assertSame($role !== EmployeeRole::Staff, $auth->roleDefault($user, CustomerReturnPermission::View));
            $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), $auth->roleDefault($user, CustomerReturnPermission::Receive));
            $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), $auth->roleDefault($user, CustomerReturnPermission::Inspect));
        }
    }

    public function test_one_page_pass_is_atomic_retry_safe_and_uses_existing_receipt_and_qc_services(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $input = $this->onePageInput($order, $warehouse, 'pass');
        $key = (string) Str::uuid();

        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $retry = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);

        $this->assertSame($return->id, $retry->id);
        $this->assertSame(CustomerReturnStatus::Completed, $return->refresh()->status);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 1);
        $this->assertDatabaseCount('customer_returns', 1);
        $this->assertDatabaseCount('customer_return_quarantines', 0);
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertDontSee('Continue QC')->assertSee('QC Results & Evidence')
            ->assertSee('PASS')->assertSee('Available Inventory');
    }

    public function test_one_page_fail_posts_damaged_only_and_requires_actual_problem(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $input = $this->onePageInput($order, $warehouse, 'fail');
        $input['items'][0]['problem_type'] = 'defective';
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());

        $this->assertSame(CustomerReturnStatus::Completed, $return->refresh()->status);
        $this->assertSame(3, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $inspection = $return->items->sole()->inspections()->sole();
        $damageReference = $inspection->damagedStockEvent->reference;
        $this->assertSame('defective', $inspection->problem_type);
        $this->assertSame('Screen failure', $inspection->notes);
        $this->assertSame(1, app(DamagedItemsQueueService::class)->query($owner, [
            'status' => 'damaged', 'search' => $return->items->sole()->sku_snapshot,
        ])->where('reference', $damageReference)->count());
        $this->assertDatabaseCount('safet_claims', 0);
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee('QC Results & Evidence')->assertSee('FAIL')
            ->assertSee('Defective')->assertSee('Screen failure')
            ->assertSee('Damaged Inventory')->assertSee($damageReference)
            ->assertSee('Not eligible: this order has no marketplace.');
    }

    public function test_completed_qc_shows_conflicting_evidence_for_review_without_changing_posted_inventory(): void
    {
        Storage::fake('local');
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit(
            $this->onePageInput($order, $warehouse, 'pass'), $owner, true, (string) Str::uuid(),
        );
        $movements = StockMovement::query()->count();
        app(CustomerReturnEvidenceService::class)->record($return->items->sole(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'wrong_item', 'remarks' => 'Received item does not match the shipped one',
        ], UploadedFile::fake()->image('different-item.jpg'));

        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee('Owner/Admin review is needed; inventory has not been changed.');
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertDatabaseCount('safet_claims', 0);
    }

    public function test_eligible_defective_return_creates_one_unfiled_claim_and_evidence_readiness_updates_on_upload(): void
    {
        Storage::fake('local');
        $platform = MarketplacePlatform::factory()->create(['customer_return_claims_enabled' => true]);
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000', $platform);
        $input = $this->onePageInput($order, $warehouse, 'fail');
        $input['items'][0]['problem_type'] = 'defective';
        $key = (string) Str::uuid();
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $claim = SafetClaim::query()->sole();

        $this->assertSame('Defective', $claim->claim_reason);
        $this->assertSame('Evidence Pending', $claim->evidenceReadiness());
        $this->assertNull($claim->external_claim_reference);
        $this->assertSame($return->id, app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key)->id);
        $this->assertDatabaseCount('safet_claims', 1);
        $this->assertSame(1, $inventory->refresh()->damaged_quantity);
        app(CustomerReturnEvidenceService::class)->record($return->items->sole(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'defective',
        ], UploadedFile::fake()->image('defect.jpg'));
        $this->assertSame('Evidence Ready', $claim->evidenceReadiness());
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee($claim->reference)->assertSee('Evidence Ready');
        $this->withToken($owner->createToken('Completed return evidence')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence?q='.$order->reference)
            ->assertOk()->assertJsonFragment(['return_reference' => $return->reference]);
    }

    public function test_marketplace_identity_exception_opens_a_separate_case_without_original_sku_inventory(): void
    {
        Storage::fake('local');
        $platform = MarketplacePlatform::factory()->create(['customer_return_claims_enabled' => true]);
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000', $platform);
        $input = $this->onePageInput($order, $warehouse, 'quarantine');
        $input['items'][0]['problem_type'] = 'wrong_item';
        $input['items'][0]['actual_custody_quantity'] = 1;
        $input['items'][0]['actual_product_name'] = 'Unrelated laptop';
        $key = (string) Str::uuid();
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $case = $return->quarantines()->sole();

        $this->assertMatchesRegularExpression('/^RCE-\d{4}-\d{6}$/', $case->claim_case_reference);
        $this->assertSame('Evidence Pending', $case->evidenceReadiness());
        $this->assertSame(1, app(DamagedItemsQueueService::class)->quarantinedReturns($owner)->total());
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->assertSame(0, app(DamagedItemsQueueService::class)->quarantinedReturns($staff)->total());
        $this->assertSame(0, app(DamagedItemsQueueService::class)->summary($owner, [])['units']);
        $this->assertDatabaseCount('safet_claims', 0);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
        $this->assertSame($return->id, app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key)->id);

        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee($case->claim_case_reference)->assertSee('Review Wrong / Different Item')
            ->assertDontSee('Continue QC');
        Livewire::actingAs($owner)->test(DamagedItems::class)
            ->assertSee('Returns Needing Review')->assertSee('Unrelated laptop')
            ->assertSee($case->claim_case_reference);
        $this->withToken($owner->createToken('Quarantined return evidence')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/qc/return-evidence?q='.$order->reference)
            ->assertOk()->assertJsonFragment(['return_reference' => $return->reference]);
        app(CustomerReturnEvidenceService::class)->record($return->items->sole(), $owner, [
            'request_id' => (string) Str::uuid(), 'upload_id' => (string) Str::uuid(),
            'problem_type' => 'wrong_item', 'actual_product_name' => 'Unrelated laptop',
        ], UploadedFile::fake()->image('wrong-item.jpg'));
        $this->assertSame('Evidence Ready', $case->evidenceReadiness());
        app(CustomerReturnQuarantineService::class)->review($return->items->sole(), [
            'disposition' => 'retain_in_quarantine', 'review_notes' => 'Keep the actual item for review.',
            'review_key' => (string) Str::uuid(),
        ], $owner);
        $this->assertSame(1, $case->refresh()->currentCustodyQuantity());
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_one_page_splits_partial_pass_and_fail_quantities_of_one_product(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(5, 3, '100.0000');
        $input = $this->onePageInput($order, $warehouse, 'mixed');
        $input['items'][0]['quantity'] = 3;
        $input['items'][0]['sellable_quantity'] = 2;
        $input['items'][0]['damaged_quantity'] = 1;
        $input['items'][0]['problem_type'] = 'damaged';

        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());
        $this->assertSame(CustomerReturnStatus::Completed, $return->status);
        $this->assertSame(4, $inventory->refresh()->available_quantity);
        $this->assertSame(1, $inventory->damaged_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 2);
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
            ->assertSee('Mixed')->assertSee('PASS 2')->assertSee('FAIL 1');
    }

    public function test_wrong_item_and_empty_package_never_create_original_product_inventory(): void
    {
        foreach (['wrong_item', 'empty_package'] as $problem) {
            [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
            $input = $this->onePageInput($order, $warehouse, 'quarantine');
            $input['items'][0]['problem_type'] = $problem;
            $input['items'][0]['actual_custody_quantity'] = $problem === 'empty_package' ? 0 : 1;
            $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());

            $this->assertSame(CustomerReturnStatus::QcPending, $return->refresh()->status);
            $this->assertSame(3, $inventory->refresh()->available_quantity);
            $this->assertSame(0, $inventory->qc_pending_quantity);
            $this->assertSame(0, $inventory->damaged_quantity);
            $this->assertSame(0, app(QcPendingQueueService::class)->summary($owner, [])['units']);
            $this->assertDatabaseCount('customer_return_refunds', 0);
            Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->id])
                ->assertSee('Wrong / Different Items Held for Verification')
                ->assertSee('Review Wrong / Different Item');
            $this->assertSame($problem === 'empty_package' ? 0 : 1, $return->quarantines()->sole()->actual_custody_quantity);
            $this->assertDatabaseMissing('stock_movements', [
                'source_type' => $return->items->sole()->getMorphClass(),
                'source_id' => $return->items->sole()->id,
                'movement_type' => StockMovementType::CustomerReturnCompanyReceipt->value,
            ]);
            app(CustomerReturnQuarantineService::class)->review($return->items->sole(), [
                'disposition' => $problem === 'empty_package' ? 'empty_package_confirmed' : 'retain_in_quarantine',
                'actual_custody_quantity' => $problem === 'empty_package' ? 0 : 1,
                'review_notes' => 'Checked physical custody; the shipped product was not recovered.',
                'review_key' => (string) Str::uuid(),
            ], $owner);
            $this->assertSame(CustomerReturnStatus::Completed, $return->refresh()->status);
            $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        }
    }

    public function test_save_pending_keeps_draft_without_package_then_receives_to_qc_pending_without_inspection(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 2, '100.0000');
        $input = $this->onePageInput($order, $warehouse, 'pending');
        $input['physical_received'] = false;
        $draft = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, false, (string) Str::uuid());
        $this->assertSame(CustomerReturnStatus::Draft, $draft->status);
        $this->assertNull($draft->received_at);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);

        $input['physical_received'] = true;
        $pending = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, false, (string) Str::uuid());
        $this->assertSame(CustomerReturnStatus::QcPending, $pending->status);
        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $pending->id])
            ->assertSee('Continue QC');
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 0);
    }

    public function test_one_page_rejects_marketplace_shortcut_without_inventory_change(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $warehouse->forceFill(['location_type' => InventoryLocationType::MarketplaceFulfilment])->save();
        $before = $inventory->refresh()->toArray();

        try {
            app(CustomerReturnUnifiedWorkflowService::class)->submit($this->onePageInput($order, $warehouse, 'pass'), $owner, true, (string) Str::uuid());
            $this->fail('Marketplace workflow must not be bypassed.');
        } catch (CustomerReturnException $exception) {
            $this->assertStringContainsString('Marketplace Return', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertSame($before['qc_pending_quantity'], $inventory->refresh()->qc_pending_quantity);
    }

    public function test_owner_review_corrects_an_existing_provisional_wrong_item_receipt_once(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $item = $return->items->sole();
        CustomerReturnFinding::query()->create([
            'customer_return_item_id' => $item->id, 'recorded_by_user_id' => $owner->id,
            'request_id' => (string) Str::uuid(), 'problem_type' => 'wrong_item',
            'actual_product_name' => 'Unrelated item', 'recorded_at' => now(),
        ]);
        $key = (string) Str::uuid();
        $input = ['disposition' => 'retain_in_quarantine', 'actual_custody_quantity' => 1,
            'review_notes' => 'Verified unrelated physical item.', 'review_key' => $key];
        $review = app(CustomerReturnQuarantineService::class)->review($item, $input, $owner);

        $this->assertSame('resolved', $review->status);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame('0.0000', $inventory->qc_pending_value);
        $this->assertSame(3, $inventory->available_quantity);
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::CustomerReturnReceiptCorrection)->count());
        $this->assertSame($review->id, app(CustomerReturnQuarantineService::class)->review($item, $input, $owner)->id);
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::CustomerReturnReceiptCorrection)->count());
    }

    public function test_marketplace_wrong_item_review_remains_on_hold_without_an_approved_ledger_rule(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $return = $this->createReturn($owner, $order, $warehouse, 1);
        app(ReceiveCustomerReturn::class)->handle($return, $owner);
        $item = $return->items->sole();
        CustomerReturnFinding::query()->create([
            'customer_return_item_id' => $item->id, 'recorded_by_user_id' => $owner->id,
            'request_id' => (string) Str::uuid(), 'problem_type' => 'wrong_item',
            'actual_product_name' => 'Unrelated item', 'recorded_at' => now(),
        ]);
        $warehouse->forceFill(['location_type' => InventoryLocationType::MarketplaceFulfilment])->save();

        Livewire::actingAs($owner)->test(ViewCustomerReturnPage::class, ['record' => $return->getRouteKey()])
            ->assertSee('Marketplace identity hold')
            ->assertSee('Inventory and financial resolution is on hold');
        Livewire::actingAs($owner)->test(ListCustomerReturns::class)
            ->assertSee('Owner/Admin review pending');

        try {
            app(CustomerReturnQuarantineService::class)->review($item, [
                'disposition' => 'retain_in_quarantine', 'actual_custody_quantity' => 1,
                'review_notes' => 'Physical discrepancy confirmed.', 'review_key' => (string) Str::uuid(),
            ], $owner);
            $this->fail('Marketplace identity discrepancies must stay on hold.');
        } catch (CustomerReturnException $exception) {
            $this->assertStringContainsString('approved inventory and ledger resolution rule', $exception->getMessage());
        }

        $this->assertSame(CustomerReturnStatus::QcPending, $return->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
        $this->assertDatabaseCount('customer_return_quarantines', 0);
        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovementType::CustomerReturnReceiptCorrection)->count());
    }

    public function test_one_page_buttons_are_present_without_generic_repeated_create(): void
    {
        [$owner] = $this->fulfilledOrder(4, 1, '100.0000');
        Livewire::actingAs($owner)->test(CreateCustomerReturnPage::class)
            ->assertSee('Create + Receive + Complete QC')
            ->assertSee('Save Pending')
            ->assertSee('Package physically received')
            ->assertDontSee('Create & create another');
    }

    public function test_web_one_page_submits_create_receive_and_qc_from_the_same_form(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        Livewire::actingAs($owner)->test(CreateCustomerReturnPage::class)
            ->fillForm($this->onePageInput($order, $warehouse, 'pass'))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(CustomerReturnStatus::Completed, CustomerReturn::query()->sole()->status);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_create_only_manager_sees_save_pending_but_not_the_unauthorized_one_click_action(): void
    {
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);

        Livewire::actingAs($manager)->test(CreateCustomerReturnPage::class)
            ->assertSee('Save Pending')
            ->assertDontSee('Create + Receive + Complete QC')
            ->assertDontSee('Package physically received');
    }

    public function test_non_owner_cannot_dispose_wrong_item_even_after_receipt(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $input = $this->onePageInput($order, $warehouse, 'quarantine');
        $input['items'][0]['problem_type'] = 'wrong_item';
        $input['items'][0]['actual_custody_quantity'] = 1;
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);

        try {
            app(CustomerReturnQuarantineService::class)->review($return->items->sole(), [
                'disposition' => 'returned_to_sender', 'actual_custody_quantity' => 1,
                'review_notes' => 'Not authorized', 'review_key' => (string) Str::uuid(),
            ], $manager);
            $this->fail('A non-Owner/Admin user cannot resolve quarantine.');
        } catch (AuthorizationException) {
        }
        $this->assertSame('awaiting_review', $return->quarantines()->sole()->status);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
    }

    public function test_one_page_handles_two_products_partial_quantities_and_mixed_qc_results(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $firstStock = ProductInventory::factory()->create(['product_id' => $first->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'average_cost' => '100.0000']);
        $secondStock = ProductInventory::factory()->create(['product_id' => $second->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, null, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($first->id, 2, '500.00'), new OrderItemData($second->id, 2, '500.00')],
            (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');
        $firstItem = $order->fulfillment->items->firstWhere('product_id', $first->id);
        $secondItem = $order->fulfillment->items->firstWhere('product_id', $second->id);

        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit([
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id,
            'physical_received' => true,
            'items' => [
                ['order_fulfillment_item_id' => $firstItem->id, 'quantity' => 1,
                    'return_reason' => 'other', 'qc_outcome' => 'pass'],
                ['order_fulfillment_item_id' => $secondItem->id, 'quantity' => 2,
                    'return_reason' => 'defective', 'qc_outcome' => 'fail', 'problem_type' => 'defective'],
            ],
        ], $owner, true, (string) Str::uuid());

        $this->assertSame(CustomerReturnStatus::Completed, $return->status);
        $this->assertSame(3, $firstStock->refresh()->available_quantity);
        $this->assertSame(2, $secondStock->refresh()->available_quantity);
        $this->assertSame(2, $secondStock->damaged_quantity);
        $this->assertSame(0, $firstStock->qc_pending_quantity + $secondStock->qc_pending_quantity);
        $this->assertSame(2, $return->items()->count());
        $this->assertDatabaseCount('customer_return_inspections', 2);
    }

    public function test_save_pending_can_finish_one_item_while_another_item_awaits_qc(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $firstStock = ProductInventory::factory()->create(['product_id' => $first->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 2, 'average_cost' => '100.0000']);
        $secondStock = ProductInventory::factory()->create(['product_id' => $second->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 2, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, null, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($first->id, 1, '500.00'), new OrderItemData($second->id, 1, '500.00')],
            (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit([
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id, 'physical_received' => true,
            'items' => [
                ['order_fulfillment_item_id' => $order->fulfillment->items->firstWhere('product_id', $first->id)->id,
                    'quantity' => 1, 'return_reason' => 'other', 'qc_outcome' => 'pass'],
                ['order_fulfillment_item_id' => $order->fulfillment->items->firstWhere('product_id', $second->id)->id,
                    'quantity' => 1, 'return_reason' => 'other', 'qc_outcome' => 'pending'],
            ],
        ], $owner, false, (string) Str::uuid());

        $this->assertSame(CustomerReturnStatus::QcPending, $return->status);
        $this->assertSame(2, $firstStock->refresh()->available_quantity);
        $this->assertSame(0, $firstStock->qc_pending_quantity);
        $this->assertSame(1, $secondStock->refresh()->available_quantity);
        $this->assertSame(1, $secondStock->qc_pending_quantity);
        $this->assertDatabaseCount('customer_return_inspections', 1);
    }

    public function test_one_page_requires_receive_and_inspect_permissions_and_rejects_changed_retry_payload(): void
    {
        [$owner, , $warehouse, $inventory, $order] = $this->fulfilledOrder(4, 1, '100.0000');
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        $input = $this->onePageInput($order, $warehouse, 'pass');
        try {
            app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $manager, true, (string) Str::uuid());
            $this->fail('Manager cannot receive or inspect merely by having return.create.');
        } catch (AuthorizationException) {
        }
        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);

        $key = (string) Str::uuid();
        app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $input['items'][0]['qc_outcome'] = 'fail';
        $input['items'][0]['problem_type'] = 'damaged';
        $this->expectException(CustomerReturnException::class);
        app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
    }

    private function onePageInput($order, Warehouse $warehouse, string $outcome): array
    {
        return [
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id,
            'physical_received' => true,
            'items' => [[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => 1, 'return_reason' => 'other', 'qc_outcome' => $outcome,
                'qc_remarks' => 'Screen failure',
            ]],
        ];
    }

    private function fulfilledOrder(int $available, int $quantity, string $cost, ?MarketplacePlatform $platform = null): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => $available, 'average_cost' => $cost]);
        $data = new SaveAndReserveOrderData($warehouse->id, $platform?->id, null, now()->toDateString(), $owner->employee->id, null, [new OrderItemData($product->id, $quantity, '500.00')], (string) Str::uuid());
        $order = app(SaveAsShippedOrder::class)->handle($data, $owner)->load('fulfillment.items');

        return [$owner->refresh(), $product, $warehouse, $inventory, $order];
    }

    private function createReturn(User $owner, $order, Warehouse $warehouse, int $quantity): CustomerReturn
    {
        return app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData($order->id, $warehouse->id, [['order_fulfillment_item_id' => $order->fulfillment->items->first()->id, 'quantity' => $quantity, 'return_reason' => 'other']], (string) Str::uuid()), $owner);
    }
}
