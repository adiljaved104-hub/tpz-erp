<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\Actions\Returns\CreateCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\CreateMarketplaceReturnRemovalData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\DTOs\Returns\MarketplaceRemovalItemData;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceRemovalSourceStockType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\MarketplaceReturnPermission;
use App\Enums\MarketplaceReturnRemovalStatus;
use App\Enums\StockMovementType;
use App\Exceptions\CustomerReturnException;
use App\Filament\Resources\CustomerReturns\Pages\CreateCustomerReturn as CreateCustomerReturnPage;
use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use App\Filament\Resources\MarketplaceReturnRemovals\Pages\CreateMarketplaceReturnRemoval as CreateMarketplaceReturnRemovalPage;
use App\Filament\Resources\MarketplaceReturnRemovals\Pages\ListMarketplaceReturnRemovals;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\MarketplaceReturnRemoval;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\MarketplaceReturnAuthorization;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\ReferenceSequenceService;
use App\Services\Returns\MarketplaceReturnService;
use App\Services\Returns\QcPendingQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MarketplaceReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_marketplace_disposition_removal_company_receipt_and_existing_qc_preserve_value(): void
    {
        [$owner, $product, $marketplace, $company, $marketplaceInventory, $order] = $this->marketplaceOrder();
        $marketplace->marketplacePlatform->forceFill(['customer_return_claims_enabled' => true, 'claim_program_name' => 'Safe-T'])->save();
        $cogs = $order->fulfillment->items->sole()->cogs_total;
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => 3,
                'return_reason' => 'damaged_by_customer',
            ]],
            (string) Str::uuid(),
        ), $owner);
        $this->assertNull($return->receiving_warehouse_id);
        $this->assertSame($marketplace->id, $return->fulfillment_warehouse_id);

        $service = app(MarketplaceReturnService::class);
        $service->disposition($return->items->sole(), 2, 1, (string) Str::uuid(), $owner);
        $this->assertDatabaseCount('safet_claims', 0);

        $marketplaceInventory->refresh();
        $this->assertSame(4, $marketplaceInventory->available_quantity);
        $this->assertSame(1, $marketplaceInventory->marketplace_non_sellable_quantity);
        $this->assertSame('100.0000', $marketplaceInventory->marketplace_non_sellable_value);
        $this->assertSame('100.0000', $marketplaceInventory->average_cost);
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $product->id, 'warehouse_id' => $company->id]);
        $this->assertSame($cogs, $order->fulfillment->items->sole()->fresh()->cogs_total);

        $beforeOwned = app(InventoryLocationOverviewService::class)->forUser($owner)->sole()['total_owned'];
        $removal = $service->requestRemoval($return->items->sole(), 1, $company->id, (string) Str::uuid(), $owner);
        $this->assertSame(MarketplaceReturnRemovalStatus::Requested, $removal->status);
        $this->assertSame(1, $marketplaceInventory->fresh()->marketplace_non_sellable_quantity);

        $service->dispatch($removal, (string) Str::uuid(), $owner);
        $this->assertSame(0, $marketplaceInventory->fresh()->marketplace_non_sellable_quantity);
        $overview = app(InventoryLocationOverviewService::class)->forUser($owner)->sole();
        $this->assertSame(1, $overview['return_in_transit']);
        $this->assertSame($beforeOwned, $overview['total_owned']);

        $service->receive($removal->refresh(), (string) Str::uuid(), $owner);
        $destination = ProductInventory::query()->whereBelongsTo($product)->whereBelongsTo($company)->sole();
        $this->assertSame(1, $destination->qc_pending_quantity);
        $this->assertSame('100.0000', $destination->qc_pending_value);
        $this->assertSame(0, $destination->available_quantity);
        $this->assertNull($destination->average_cost);
        $this->assertSame(1, app(QcPendingQueueService::class)->summary($owner, [])['units']);

        app(InspectCustomerReturnItem::class)->handle(
            $return->items->sole(),
            new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()),
            $owner,
        );

        $destination->refresh();
        $this->assertSame(0, $destination->qc_pending_quantity);
        $this->assertSame(1, $destination->damaged_quantity);
        $this->assertSame('100.0000', $destination->average_cost);
        $this->assertSame(CustomerReturnStatus::Completed, $return->fresh()->status);
        $claim = SafetClaim::query()->sole();
        $this->assertSame($return->id, $claim->customer_return_id);
        $this->assertSame($marketplace->marketplace_platform_id, $claim->marketplace_platform_id);
        $damage = DamagedStockEvent::query()->sole();
        $this->assertSame($return->id, $damage->customer_return_id);
        $this->assertSame($marketplace->marketplace_platform_id, $damage->marketplace_platform_id);
        $this->assertSame($destination->id, $damage->product_inventory_id);
        $this->assertSame($cogs, $order->fulfillment->items->sole()->fresh()->cogs_total);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => StockMovementType::MarketplaceReturnSellable->value]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => StockMovementType::MarketplaceReturnNonSellable->value]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => StockMovementType::MarketplaceReturnRemovalDispatch->value]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => StockMovementType::MarketplaceReturnCompanyReceipt->value]);
    }

    public function test_marketplace_stage_ceilings_prevent_double_disposition_and_over_removal(): void
    {
        [$owner, , , $company, $inventory, $order] = $this->marketplaceOrder();
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 2, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
        $service = app(MarketplaceReturnService::class);
        $service->disposition($return->items->sole(), 1, 1, (string) Str::uuid(), $owner);
        $available = $inventory->fresh()->available_quantity;

        try {
            $service->disposition($return->items->sole(), 1, 0, (string) Str::uuid(), $owner);
            $this->fail('Over-disposition was not rejected.');
        } catch (CustomerReturnException) {
            $this->assertSame($available, $inventory->fresh()->available_quantity);
        }

        $service->requestRemoval($return->items->sole(), 1, $company->id, (string) Str::uuid(), $owner);
        $this->expectException(CustomerReturnException::class);
        $service->requestRemoval($return->items->sole(), 1, $company->id, (string) Str::uuid(), $owner);
    }

    public function test_marketplace_return_create_form_shows_original_location_and_hides_company_destination(): void
    {
        [$owner, , $marketplace, , , $order] = $this->marketplaceOrder();

        Livewire::actingAs($owner)
            ->test(CreateCustomerReturnPage::class)
            ->fillForm(['order_id' => $order->id])
            ->assertSchemaComponentHidden('receiving_warehouse_id')
            ->assertSee("Marketplace Return Location: {$marketplace->name}");
    }

    public function test_staff_override_still_requires_matching_active_product_and_platform_responsibility(): void
    {
        [$owner, $product, , $company, , $order] = $this->marketplaceOrder();
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
        app(MarketplaceReturnService::class)->disposition($return->items->sole(), 0, 1, (string) Str::uuid(), $owner);

        $staff = User::factory()->create();
        $employee = Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id,
            'permission_key' => MarketplaceReturnPermission::RequestRemoval->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Marketplace Returns test',
        ]);
        $staff->refresh()->load('employee');
        $authorization = app(MarketplaceReturnAuthorization::class);
        $this->assertFalse($authorization->allowsReturnItem($staff, MarketplaceReturnPermission::RequestRemoval, $return->items->sole()));

        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $employee->id,
            'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $order->marketplace_platform_id]);

        $this->assertTrue($authorization->allowsReturnItem($staff, MarketplaceReturnPermission::RequestRemoval, $return->items->sole()));
        $assignment->forceFill(['status' => 'inactive', 'ended_at' => now(), 'active_fingerprint' => null])->save();
        $this->assertFalse($authorization->allowsReturnItem($staff, MarketplaceReturnPermission::RequestRemoval, $return->items->sole()));
    }

    public function test_bulk_removal_supports_linked_non_sellable_and_general_sellable_without_consuming_reserved(): void
    {
        [$owner, $product, $marketplace, $company, $inventory, $order] = $this->marketplaceOrder();
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
        $service = app(MarketplaceReturnService::class);
        $service->disposition($return->items->sole(), 0, 1, (string) Str::uuid(), $owner);
        $generalProduct = Product::factory()->create(['cost_price' => '9999.0000']);
        $generalInventory = ProductInventory::factory()->create([
            'product_id' => $generalProduct->id,
            'warehouse_id' => $marketplace->id,
            'available_quantity' => 5,
            'reserved_quantity' => 2,
            'average_cost' => '200.0000',
        ]);
        $beforeOwned = app(InventoryLocationOverviewService::class)->summaryForUser($owner)['total_company_stock'];
        $removal = $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $order->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [
                new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::NonSellable, 1, $return->items->sole()->id),
                new MarketplaceRemovalItemData($generalProduct->id, MarketplaceRemovalSourceStockType::Sellable, 2),
            ],
            (string) Str::uuid(),
        ), $owner);

        $this->assertCount(2, $removal->items);
        $this->assertNull($removal->items->firstWhere('product_id', $generalProduct->id)->customer_return_item_id);
        $service->dispatch($removal, (string) Str::uuid(), $owner);
        $this->assertSame(0, $inventory->fresh()->marketplace_non_sellable_quantity);
        $generalInventory->refresh();
        $this->assertSame(3, $generalInventory->available_quantity);
        $this->assertSame(2, $generalInventory->reserved_quantity);
        $this->assertSame('200.0000', $generalInventory->average_cost);
        $this->assertSame(3, app(InventoryLocationOverviewService::class)->summaryForUser($owner)['return_in_transit']);
        $this->assertSame($beforeOwned, app(InventoryLocationOverviewService::class)->summaryForUser($owner)['total_company_stock']);

        $service->receive($removal->refresh(), (string) Str::uuid(), $owner);
        $this->assertSame(3, app(QcPendingQueueService::class)->summary($owner, [])['units']);
        $generalLine = $removal->items()->where('product_id', $generalProduct->id)->sole();
        $this->assertSame('400.0000', $generalLine->carried_value);
        $service->inspectRemovalItem($generalLine, new InspectCustomerReturnItemData(2, 0, (string) Str::uuid()), $owner);
        $generalDestination = ProductInventory::query()->where('product_id', $generalProduct->id)->where('warehouse_id', $company->id)->sole();
        $this->assertSame(2, $generalDestination->available_quantity);
        $this->assertSame(0, $generalDestination->qc_pending_quantity);
        $this->assertSame('200.0000', $generalDestination->average_cost);

        $this->expectException(CustomerReturnException::class);
        $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $order->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [new MarketplaceRemovalItemData($generalProduct->id, MarketplaceRemovalSourceStockType::Sellable, 2)],
            (string) Str::uuid(),
        ), $owner);
    }

    public function test_marketplace_removal_reference_is_reserved_before_the_business_transaction(): void
    {
        [$owner, $product, $marketplace, $company] = $this->marketplaceOrder();
        $probe = new class extends ReferenceSequenceService
        {
            public int $transactionLevel = -1;

            public function nextMarketplaceReturnRemovalReference(?int $year = null): string
            {
                $this->transactionLevel = DB::transactionLevel();

                return 'MRV-2026-999999';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambientTestTransactionLevel = DB::transactionLevel();

        $removal = app(MarketplaceReturnService::class)->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1)],
            (string) Str::uuid(),
        ), $owner);

        $this->assertSame($ambientTestTransactionLevel, $probe->transactionLevel);
        $this->assertSame('MRV-2026-999999', $removal->reference);
        $this->assertCount(1, $removal->items);
    }

    public function test_marketplace_removal_list_renders_each_record_with_its_view_url(): void
    {
        [$owner, $product, $marketplace, $company] = $this->marketplaceOrder();
        $removal = app(MarketplaceReturnService::class)->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1)],
            (string) Str::uuid(),
        ), $owner);

        Livewire::actingAs($owner)
            ->test(ListMarketplaceReturnRemovals::class)
            ->assertSuccessful()
            ->assertSee($removal->reference)
            ->assertSee(MarketplaceReturnRemovalResource::getUrl('view', ['record' => $removal]), escape: false);
    }

    public function test_bulk_dispatch_reserves_one_movement_reference_per_line_before_transaction(): void
    {
        [$owner, $product, $marketplace, $company] = $this->marketplaceOrder();
        $secondProduct = Product::factory()->create(['cost_price' => '200.0000']);
        ProductInventory::factory()->create([
            'product_id' => $secondProduct->id,
            'warehouse_id' => $marketplace->id,
            'available_quantity' => 2,
            'reserved_quantity' => 1,
            'average_cost' => '200.0000',
        ]);
        $removal = app(MarketplaceReturnService::class)->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [
                new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1),
                new MarketplaceRemovalItemData($secondProduct->id, MarketplaceRemovalSourceStockType::Sellable, 1),
            ],
            (string) Str::uuid(),
        ), $owner);
        $probe = new class extends ReferenceSequenceService
        {
            /** @var array<int, int> */
            public array $transactionLevels = [];

            public function nextStockMovementReference(): string
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return 'SM-'.str_pad((string) (900000 + count($this->transactionLevels)), 6, '0', STR_PAD_LEFT);
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambientTestTransactionLevel = DB::transactionLevel();

        $dispatched = app(MarketplaceReturnService::class)->dispatch($removal, (string) Str::uuid(), $owner);

        $this->assertSame([$ambientTestTransactionLevel, $ambientTestTransactionLevel], $probe->transactionLevels);
        $this->assertSame(MarketplaceReturnRemovalStatus::Dispatched, $dispatched->status);
        $this->assertSame(2, $dispatched->items()->whereColumn('dispatched_quantity', 'quantity')->count());
        $this->assertSame(
            ['SM-900001', 'SM-900002'],
            DB::table('stock_movements')->where('source_type', 'marketplace_return_removal_item')->whereIn('source_id', $removal->items()->pluck('id'))->orderBy('reference')->pluck('reference')->all(),
        );
    }

    public function test_failed_bulk_dispatch_rolls_back_every_line_and_preserves_reserved_stock(): void
    {
        [$owner, $product, $marketplace, $company, $firstInventory] = $this->marketplaceOrder();
        $secondProduct = Product::factory()->create(['cost_price' => '200.0000']);
        $secondInventory = ProductInventory::factory()->create([
            'product_id' => $secondProduct->id,
            'warehouse_id' => $marketplace->id,
            'available_quantity' => 3,
            'reserved_quantity' => 1,
            'average_cost' => '200.0000',
        ]);
        $service = app(MarketplaceReturnService::class);
        $removal = $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [
                new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1),
                new MarketplaceRemovalItemData($secondProduct->id, MarketplaceRemovalSourceStockType::Sellable, 2),
            ],
            (string) Str::uuid(),
        ), $owner);
        $firstInventory->refresh();
        $firstBefore = $firstInventory->only(['available_quantity', 'reserved_quantity', 'average_cost']);
        $secondInventory->forceFill(['available_quantity' => 1, 'reserved_quantity' => 1])->save();
        $secondBefore = $secondInventory->fresh()->only(['available_quantity', 'reserved_quantity', 'average_cost']);
        $movementCount = DB::table('stock_movements')->count();

        try {
            $service->dispatch($removal, (string) Str::uuid(), $owner);
            $this->fail('Dispatch should fail when one bulk line no longer has sufficient Sellable inventory.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Sellable inventory is insufficient for Marketplace Removal dispatch.', $exception->getMessage());
        }

        $this->assertSame(MarketplaceReturnRemovalStatus::Requested, $removal->fresh()->status);
        $this->assertSame([0, 0], $removal->items()->orderBy('id')->pluck('dispatched_quantity')->all());
        $this->assertSame($firstBefore, $firstInventory->fresh()->only(['available_quantity', 'reserved_quantity', 'average_cost']));
        $this->assertSame($secondBefore, $secondInventory->fresh()->only(['available_quantity', 'reserved_quantity', 'average_cost']));
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
    }

    public function test_receive_reserves_references_before_transaction_and_posts_exact_qc_pending_value_once(): void
    {
        [$owner, $product, $marketplace, $company] = $this->marketplaceOrder();
        $service = app(MarketplaceReturnService::class);
        $removal = $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 2)],
            (string) Str::uuid(),
        ), $owner);
        $removal = $service->dispatch($removal, (string) Str::uuid(), $owner);
        $probe = new class extends ReferenceSequenceService
        {
            /** @var array<int, int> */
            public array $transactionLevels = [];

            public function nextStockMovementReference(): string
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return 'SM-910001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambientTestTransactionLevel = DB::transactionLevel();
        $service = app(MarketplaceReturnService::class);
        $receiveKey = (string) Str::uuid();
        $movementCount = DB::table('stock_movements')->count();
        $bucketCount = DB::table('stock_movement_bucket_changes')->count();

        $received = $service->receive($removal, $receiveKey, $owner);

        $this->assertSame([$ambientTestTransactionLevel], $probe->transactionLevels);
        $this->assertSame(MarketplaceReturnRemovalStatus::Received, $received->status);
        $line = $received->items()->sole();
        $this->assertSame(2, $line->received_quantity);
        $destination = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $company->id)->sole();
        $this->assertSame(0, $destination->available_quantity);
        $this->assertSame(0, $destination->reserved_quantity);
        $this->assertSame(0, $destination->damaged_quantity);
        $this->assertNull($destination->average_cost);
        $this->assertSame(2, $destination->qc_pending_quantity);
        $this->assertSame('200.0000', $destination->qc_pending_value);
        $this->assertSame($movementCount + 1, DB::table('stock_movements')->count());
        $this->assertSame($bucketCount + 1, DB::table('stock_movement_bucket_changes')->count());
        $this->assertDatabaseHas('stock_movements', [
            'reference' => 'SM-910001',
            'movement_type' => StockMovementType::MarketplaceReturnCompanyReceipt->value,
            'source_type' => 'marketplace_return_removal_item',
            'source_id' => $line->id,
        ]);
        $this->assertSame(2, app(QcPendingQueueService::class)->summary($owner, [])['units']);

        $retried = $service->receive($removal, $receiveKey, $owner);
        $this->assertSame($received->id, $retried->id);
        $this->assertSame([$ambientTestTransactionLevel], $probe->transactionLevels);
        $this->assertSame($movementCount + 1, DB::table('stock_movements')->count());
        $this->assertSame($bucketCount + 1, DB::table('stock_movement_bucket_changes')->count());
        $this->assertSame(2, $destination->fresh()->qc_pending_quantity);
    }

    public function test_all_marketplace_removal_state_actions_reserve_sequence_values_before_their_transactions(): void
    {
        [$owner, $product, $marketplace, $company, , $order] = $this->marketplaceOrder();
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $order->id,
            null,
            [['order_fulfillment_item_id' => $order->fulfillment->items->sole()->id, 'quantity' => 1, 'return_reason' => 'other']],
            (string) Str::uuid(),
        ), $owner);
        $probe = new class extends ReferenceSequenceService
        {
            /** @var array<int, int> */
            public array $transactionLevels = [];

            private int $movement = 920000;

            public function nextMarketplaceReturnRemovalReference(?int $year = null): string
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return 'MRV-2026-920001';
            }

            public function nextStockMovementReference(): string
            {
                $this->transactionLevels[] = DB::transactionLevel();

                return 'SM-'.str_pad((string) ++$this->movement, 6, '0', STR_PAD_LEFT);
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambientTestTransactionLevel = DB::transactionLevel();
        $service = app(MarketplaceReturnService::class);

        $service->disposition($return->items->sole(), 0, 1, (string) Str::uuid(), $owner);
        $removal = $service->requestRemoval($return->items->sole(), 1, $company->id, (string) Str::uuid(), $owner);
        $removal = $service->dispatch($removal, (string) Str::uuid(), $owner);
        $removal = $service->receive($removal, (string) Str::uuid(), $owner);
        $service->inspectRemovalItem(
            $removal->items()->sole(),
            new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()),
            $owner,
        );

        $this->assertCount(5, $probe->transactionLevels);
        $this->assertSame(
            array_fill(0, 5, $ambientTestTransactionLevel),
            $probe->transactionLevels,
        );
        $this->assertSame(CustomerReturnStatus::Completed, $return->fresh()->status);
    }

    public function test_failed_bulk_receive_rolls_back_destination_inventory_movements_and_all_received_quantities(): void
    {
        [$owner, $product, $marketplace, $company] = $this->marketplaceOrder();
        $secondProduct = Product::factory()->create(['cost_price' => '200.0000']);
        ProductInventory::factory()->create([
            'product_id' => $secondProduct->id,
            'warehouse_id' => $marketplace->id,
            'available_quantity' => 2,
            'average_cost' => '200.0000',
        ]);
        $service = app(MarketplaceReturnService::class);
        $removal = $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
            $marketplace->marketplace_platform_id,
            $marketplace->id,
            $company->id,
            [
                new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1),
                new MarketplaceRemovalItemData($secondProduct->id, MarketplaceRemovalSourceStockType::Sellable, 1),
            ],
            (string) Str::uuid(),
        ), $owner);
        $removal = $service->dispatch($removal, (string) Str::uuid(), $owner);
        $failingBalances = new class extends InventoryBalanceService
        {
            private int $calls = 0;

            public function lockOrCreate(int $productId, int $warehouseId): ProductInventory
            {
                if (++$this->calls === 2) {
                    throw new CustomerReturnException('Simulated second-line receipt failure.');
                }

                return parent::lockOrCreate($productId, $warehouseId);
            }
        };
        $this->app->instance(InventoryBalanceService::class, $failingBalances);
        $movementCount = DB::table('stock_movements')->count();
        $bucketCount = DB::table('stock_movement_bucket_changes')->count();

        try {
            app(MarketplaceReturnService::class)->receive($removal, (string) Str::uuid(), $owner);
            $this->fail('The simulated second-line receipt failure was not propagated.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Simulated second-line receipt failure.', $exception->getMessage());
        }

        $this->assertSame(MarketplaceReturnRemovalStatus::Dispatched, $removal->fresh()->status);
        $this->assertSame([0, 0], $removal->items()->orderBy('id')->pluck('received_quantity')->all());
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $product->id, 'warehouse_id' => $company->id]);
        $this->assertDatabaseMissing('product_inventories', ['product_id' => $secondProduct->id, 'warehouse_id' => $company->id]);
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($bucketCount, DB::table('stock_movement_bucket_changes')->count());
    }

    public function test_zero_or_insufficient_sellable_availability_blocks_creation_without_business_side_effects(): void
    {
        [$owner, $product, $marketplace, $company, $inventory] = $this->marketplaceOrder();
        $service = app(MarketplaceReturnService::class);
        $inventory->refresh();
        $inventory->forceFill(['reserved_quantity' => $inventory->available_quantity])->save();

        $this->assertSame(0, $service->availableRemovalQuantity(
            $marketplace->id,
            $product->id,
            MarketplaceRemovalSourceStockType::Sellable,
        ));

        $before = [
            'removals' => MarketplaceReturnRemoval::query()->count(),
            'items' => DB::table('marketplace_return_removal_items')->count(),
            'movements' => DB::table('stock_movements')->count(),
            'inventory' => $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']),
        ];

        try {
            $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
                $marketplace->marketplace_platform_id,
                $marketplace->id,
                $company->id,
                [new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 1)],
                (string) Str::uuid(),
            ), $owner);
            $this->fail('Zero removable stock was not rejected.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Only 0 units are available for removal.', $exception->getMessage());
        }

        $this->assertSame($before['removals'], MarketplaceReturnRemoval::query()->count());
        $this->assertSame($before['items'], DB::table('marketplace_return_removal_items')->count());
        $this->assertSame($before['movements'], DB::table('stock_movements')->count());
        $this->assertSame($before['inventory'], $inventory->fresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']));

        $inventory->forceFill(['reserved_quantity' => max(0, $inventory->available_quantity - 1)])->save();
        $this->assertSame(1, $service->availableRemovalQuantity(
            $marketplace->id,
            $product->id,
            MarketplaceRemovalSourceStockType::Sellable,
        ));

        try {
            $service->requestBulkRemoval(new CreateMarketplaceReturnRemovalData(
                $marketplace->marketplace_platform_id,
                $marketplace->id,
                $company->id,
                [new MarketplaceRemovalItemData($product->id, MarketplaceRemovalSourceStockType::Sellable, 2)],
                (string) Str::uuid(),
            ), $owner);
            $this->fail('Over-removal was not rejected.');
        } catch (CustomerReturnException $exception) {
            $this->assertSame('Only 1 units are available for removal.', $exception->getMessage());
        }

        $this->assertSame($before['removals'], MarketplaceReturnRemoval::query()->count());
        $this->assertSame($before['items'], DB::table('marketplace_return_removal_items')->count());
        $this->assertSame($before['movements'], DB::table('stock_movements')->count());
    }

    public function test_create_form_reacts_to_zero_sellable_availability_and_blocks_the_quantity_field(): void
    {
        [$owner, $product, $marketplace, $company, $inventory] = $this->marketplaceOrder();
        $inventory->refresh();
        $inventory->forceFill(['reserved_quantity' => $inventory->available_quantity])->save();

        Livewire::actingAs($owner)
            ->test(CreateMarketplaceReturnRemovalPage::class)
            ->fillForm([
                'marketplace_platform_id' => $marketplace->marketplace_platform_id,
                'source_warehouse_id' => $marketplace->id,
                'destination_warehouse_id' => $company->id,
                'items' => [[
                    'product_id' => $product->id,
                    'source_stock_type' => MarketplaceRemovalSourceStockType::Sellable->value,
                ]],
            ])
            ->assertSee("No removable Marketplace Sellable stock is available at {$marketplace->name}.")
            ->assertFormFieldDisabled('items.0.quantity');
    }

    private function marketplaceOrder(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $company = Warehouse::query()->where('code', 'MAIN')->sole();
        $platform = MarketplacePlatform::factory()->create([
            'return_handling_mode' => MarketplaceReturnHandlingMode::HoldNonSellableAtMarketplace,
            'default_return_receiving_warehouse_id' => $company->id,
        ]);
        $marketplace = Warehouse::factory()->create([
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $platform->id,
            'fulfillment_tag' => 'FBA',
        ]);
        $product = Product::factory()->create(['cost_price' => '9999.0000']);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $marketplace->id,
            'available_quantity' => 5,
            'average_cost' => '100.0000',
        ]);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $marketplace->id,
            $platform->id,
            'MARKETPLACE-RETURN-TEST',
            now()->toDateString(),
            $owner->employee->id,
            null,
            [new OrderItemData($product->id, 3, '500.00')],
            (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');

        return [$owner->refresh(), $product, $marketplace, $company, $inventory, $order];
    }
}
