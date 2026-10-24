<?php

namespace Tests\Feature\Returns;

use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnInspection;
use App\Models\CustomerReturnQuarantine;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ReferenceSequence;
use App\Models\SafetClaim;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReferenceSequenceService;
use App\Services\Returns\CustomerReturnUnifiedWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CustomerReturnUnifiedReferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Each test uses a new in-memory SQLite connection. Migrate forward
        // only: immutable audit migrations intentionally do not support down().
        $this->artisan('migrate', ['--force' => true])->run();
    }

    public function test_production_guard_reserves_all_multi_item_qc_and_eligible_claim_references_before_the_business_transaction(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $platform = MarketplacePlatform::factory()->create([
            'customer_return_claims_enabled' => true, 'claim_program_name' => 'Safe-T',
        ]);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $firstProduct = Product::factory()->create();
        $secondProduct = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $firstProduct->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 3, 'average_cost' => '100.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => $secondProduct->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 2, 'average_cost' => '100.0000',
        ]);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, $platform->id, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($firstProduct->id, 2, '500.00'), new OrderItemData($secondProduct->id, 1, '500.00')],
            (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');
        $failProduct = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $failProduct->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 2, 'average_cost' => '100.0000',
        ]);
        $failOrder = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, $platform->id, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($failProduct->id, 1, '500.00')], (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');

        $probe = $this->productionGuardProbe();
        $key = (string) Str::uuid();
        $input = [
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id,
            'physical_received' => true,
            'items' => [
                [
                    'order_fulfillment_item_id' => $order->fulfillment->items->firstWhere('product_id', $firstProduct->id)->id,
                    'quantity' => 2, 'return_reason' => 'damaged_by_customer', 'qc_outcome' => 'mixed',
                    'problem_type' => 'damaged', 'sellable_quantity' => 1, 'damaged_quantity' => 1,
                ],
                [
                    'order_fulfillment_item_id' => $order->fulfillment->items->firstWhere('product_id', $secondProduct->id)->id,
                    'quantity' => 1, 'return_reason' => 'other', 'qc_outcome' => 'pass',
                ],
            ],
        ];
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);

        $this->assertSame(CustomerReturnStatus::Completed, $return->status);
        $this->assertDatabaseCount('customer_return_inspections', 3);
        $this->assertSame(1, SafetClaim::query()->where('customer_return_id', $return->id)->count());
        $this->assertSame(['return', 'movement', 'movement', 'movement', 'damage', 'claim', 'movement', 'movement'], array_column($probe->levels, 'type'));
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));
        $this->assertSame($return->id, app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key)->id);
        $this->assertCount(8, $probe->levels);
        $failedQc = app(CustomerReturnUnifiedWorkflowService::class)->submit(
            $this->input($failOrder, $warehouse, 'fail', 1, [
                'return_reason' => 'damaged_by_customer', 'problem_type' => 'damaged',
            ]), $owner, true, (string) Str::uuid(),
        );
        $this->assertSame(CustomerReturnStatus::Completed, $failedQc->status);
        $this->assertSame(2, SafetClaim::query()->count());
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));
        $this->assertSame(2, CustomerReturn::query()->count());
    }

    public function test_production_guard_keeps_identity_quarantines_and_save_pending_atomic_without_unneeded_movements(): void
    {
        [$owner, $warehouse, $inventory, $order] = $this->fulfilledOrder(5);
        $probe = $this->productionGuardProbe();
        foreach (['wrong_item', 'serial_mismatch', 'empty_package'] as $problem) {
            $input = $this->input($order, $warehouse, 'quarantine', 1, [
                'problem_type' => $problem,
                'actual_custody_quantity' => $problem === 'empty_package' ? 0 : 1,
            ]);
            $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());
            $this->assertSame(CustomerReturnStatus::QcPending, $return->status);
            $this->assertSame($problem, $return->quarantines()->sole()->problem_type);
        }
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'customer_return_company_receipt')->count());

        $draftInput = $this->input($order, $warehouse, 'pending');
        $draftInput['physical_received'] = false;
        $draftKey = (string) Str::uuid();
        $draft = app(CustomerReturnUnifiedWorkflowService::class)->submit($draftInput, $owner, false, $draftKey);
        $this->assertSame(CustomerReturnStatus::Draft, $draft->status);
        $beforeRetry = count($probe->levels);
        $this->assertSame($draft->id, app(CustomerReturnUnifiedWorkflowService::class)->submit($draftInput, $owner, false, $draftKey)->id);
        $this->assertCount($beforeRetry, $probe->levels);

        $received = app(CustomerReturnUnifiedWorkflowService::class)->submit(
            $this->input($order, $warehouse, 'pending'), $owner, false, (string) Str::uuid(),
        );
        $this->assertSame(CustomerReturnStatus::QcPending, $received->status);
        $this->assertSame(1, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));
        $this->assertSame(1, collect($probe->levels)->where('type', 'movement')->count());
    }

    public function test_production_guard_reserves_eligible_exception_case_before_atomic_return_and_does_not_reuse_it(): void
    {
        [$owner, $warehouse, $inventory, $order] = $this->fulfilledOrder(1, true);
        $probe = $this->productionGuardProbe();
        $input = $this->input($order, $warehouse, 'quarantine', 1, [
            'problem_type' => 'empty_package', 'actual_custody_quantity' => 0,
        ]);
        $key = (string) Str::uuid();
        $first = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $reference = $first->quarantines()->sole()->claim_case_reference;

        $this->assertMatchesRegularExpression('/^RCE-\d{4}-\d{6}$/', $reference);
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));
        $this->assertContains('exception_case', array_column($probe->levels, 'type'));
        $before = count($probe->levels);
        $this->assertSame($first->id, app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key)->id);
        $this->assertCount($before, $probe->levels);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame(0, $inventory->damaged_quantity);
    }

    public function test_failed_exception_receipt_rolls_back_case_but_never_reuses_its_reference(): void
    {
        [$owner, $warehouse, $inventory, $order] = $this->fulfilledOrder(1, true);
        $probe = $this->productionGuardProbe();
        $input = $this->input($order, $warehouse, 'quarantine', 1, [
            'problem_type' => 'serial_mismatch', 'actual_custody_quantity' => 1,
        ]);
        $fail = true;
        CustomerReturnQuarantine::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated custody write failure');
            }
        });

        try {
            app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());
            $this->fail('The failed custody write must roll back the Return.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated custody write failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertDatabaseCount('customer_return_quarantines', 0);
        $this->assertSame(0, $inventory->refresh()->qc_pending_quantity);
        $this->assertSame(2, ReferenceSequence::query()->whereKey('return_exception_case:'.now()->year)->value('next_value'));
        $fail = false;
        $return = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, (string) Str::uuid());
        $this->assertStringEndsWith('000002', $return->quarantines()->sole()->claim_case_reference);
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));
    }

    public function test_failed_inspection_rolls_back_business_rows_but_cannot_reuse_reserved_references(): void
    {
        [$owner, $warehouse, $inventory, $order] = $this->fulfilledOrder(1);
        $probe = $this->productionGuardProbe();
        $key = (string) Str::uuid();
        $input = $this->input($order, $warehouse, 'pass');
        $beforeStockMovements = StockMovement::query()->count();
        $beforeAvailable = $inventory->refresh()->available_quantity;
        $fail = true;
        CustomerReturnInspection::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Simulated QC write failure');
            }
        });

        try {
            app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
            $this->fail('The simulated inspection failure must roll back the complete return.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated QC write failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('customer_returns', 0);
        $this->assertSame($beforeStockMovements, StockMovement::query()->count());
        $this->assertSame($beforeAvailable, $inventory->refresh()->available_quantity);
        $this->assertSame(0, $inventory->qc_pending_quantity);
        $this->assertSame(2, ReferenceSequence::query()->where('key', 'customer_return:'.now()->year)->value('next_value'));
        $this->assertSame([0], array_values(array_unique(array_column($probe->levels, 'transaction_level'))));

        $fail = false;
        $retry = app(CustomerReturnUnifiedWorkflowService::class)->submit($input, $owner, true, $key);
        $this->assertSame(CustomerReturnStatus::Completed, $retry->status);
        $this->assertStringEndsWith('000002', $retry->reference);
        $this->assertSame(3, ReferenceSequence::query()->where('key', 'customer_return:'.now()->year)->value('next_value'));

        DB::beginTransaction();
        try {
            $probe->nextCustomerReturnReference();
            $this->fail('The production reference guard must reject an active transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('References must be reserved before the business transaction', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    private function productionGuardProbe(): ReferenceSequenceService
    {
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->app['env'] = 'local';
        $this->assertFalse(app()->runningUnitTests());
        $probe = new class extends ReferenceSequenceService
        {
            public array $levels = [];

            public function nextCustomerReturnReference(?int $year = null): string
            {
                $this->record('return');

                return parent::nextCustomerReturnReference($year);
            }

            public function nextStockMovementReference(): string
            {
                $this->record('movement');

                return parent::nextStockMovementReference();
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->record('damage');

                return parent::nextDamagedStockReference($year);
            }

            public function nextSafetClaimReference(?int $year = null): string
            {
                $this->record('claim');

                return parent::nextSafetClaimReference($year);
            }

            public function nextReturnExceptionCaseReference(?int $year = null): string
            {
                $this->record('exception_case');

                return parent::nextReturnExceptionCaseReference($year);
            }

            private function record(string $type): void
            {
                $this->levels[] = ['type' => $type, 'transaction_level' => DB::connection()->transactionLevel()];
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);

        return $probe;
    }

    private function fulfilledOrder(int $quantity, bool $claimsEnabled = false): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $platform = $claimsEnabled ? MarketplacePlatform::factory()->create([
            'customer_return_claims_enabled' => true, 'claim_program_name' => 'Safe-T',
        ]) : null;
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => $quantity + 1, 'average_cost' => '100.0000',
        ]);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, $platform?->id, null, now()->toDateString(), $owner->employee->id, null,
            [new OrderItemData($product->id, $quantity, '500.00')], (string) Str::uuid(),
        ), $owner)->load('fulfillment.items');

        return [$owner->refresh(), $warehouse, $inventory, $order];
    }

    private function input($order, Warehouse $warehouse, string $outcome, int $quantity = 1, array $extra = []): array
    {
        return [
            'order_id' => $order->id, 'receiving_warehouse_id' => $warehouse->id,
            'physical_received' => true,
            'items' => [[...[
                'order_fulfillment_item_id' => $order->fulfillment->items->sole()->id,
                'quantity' => $quantity, 'return_reason' => 'other', 'qc_outcome' => $outcome,
            ], ...$extra]],
        ];
    }
}
