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
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\SafetClaimPermission;
use App\Enums\SafetClaimStatus;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\ReturnRefundException;
use App\Exceptions\SafetClaimException;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\CustomerReturns\Pages\ViewCustomerReturn;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Claims\SafetClaimService;
use App\Services\Returns\ReturnFinancialReadService;
use App\Services\Returns\ReturnRefundService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReturnRefundFinancialLinkageTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_records_once_without_changing_order_or_inventory(): void
    {
        $f = $this->foundation(2);
        $return = $this->createReturn($f, 1);
        $service = app(ReturnRefundService::class);
        $inventoryBefore = $this->inventoryState($f['inventory']);
        $orderBefore = $f['order']->fresh()->getAttributes();
        $movementsBefore = DB::table('stock_movements')->count();

        $this->assertSame('200.00', $service->suggestedAmountForReturn($return));
        $key = (string) Str::uuid();
        $refund = $service->recordForReturn($return, '195.50', today(), 'AMZ-RF-1', 'Marketplace adjustment', $key, $f['owner']);
        $retry = $service->recordForReturn($return, '195.50', today(), 'AMZ-RF-1', 'Marketplace adjustment', $key, $f['owner']);

        $this->assertSame($refund->id, $retry->id);
        $this->assertDatabaseCount('customer_return_refunds', 1);
        $this->assertSame('195.50', $refund->refund_amount);
        $this->assertSame($orderBefore, $f['order']->fresh()->getAttributes());
        $this->assertSame($inventoryBefore, $this->inventoryState($f['inventory']));
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'return.refund_recorded', 'subject_id' => $return->id]);

        $this->expectException(ReturnRefundException::class);
        $service->recordForReturn($return, '195.50', today(), null, null, (string) Str::uuid(), $f['owner']);
    }

    public function test_refund_validation_blocks_future_dates_and_non_positive_amounts(): void
    {
        $f = $this->foundation();
        $return = $this->createReturn($f, 1);
        $service = app(ReturnRefundService::class);

        foreach ([['0', today()], ['-1', today()], ['1.00', today()->addDay()]] as [$amount, $date]) {
            try {
                $service->recordForReturn($return, $amount, $date, null, null, (string) Str::uuid(), $f['owner']);
                $this->fail('Invalid refund input was accepted.');
            } catch (ValidationException) {
            }
        }

        $this->assertDatabaseCount('customer_return_refunds', 0);
    }

    public function test_warranty_refund_reuses_existing_return_and_cannot_repair_does_not_infer_refund(): void
    {
        $f = $this->foundation();
        $return = $this->createReturn($f, 1);
        $warranty = $this->createWarranty($f, $return);
        $warranty = app(WarrantyRepairService::class)->transition($warranty, WarrantyRepairStatus::UnderInspection, $f['owner']);
        $warranty = app(WarrantyRepairService::class)->transition($warranty, WarrantyRepairStatus::CannotRepair, $f['owner'], 'Not repairable');
        $this->assertDatabaseCount('customer_return_refunds', 0);

        $refund = app(ReturnRefundService::class)->recordForWarranty($warranty, '200.00', today(), null, null, (string) Str::uuid(), $f['owner']);

        $this->assertSame($return->id, $refund->customer_return_id);
        $this->assertSame($warranty->id, $refund->warranty_repair_id);
        $this->assertDatabaseCount('customer_returns', 1);
        $this->assertDatabaseCount('customer_return_refunds', 1);
    }

    public function test_warranty_without_return_creates_one_financial_return_and_no_inventory_event(): void
    {
        $f = $this->foundation();
        $warranty = $this->createWarranty($f);
        $inventoryBefore = $this->inventoryState($f['inventory']);
        $movementsBefore = DB::table('stock_movements')->count();

        $refund = app(ReturnRefundService::class)->recordForWarranty($warranty, '200.00', today(), null, null, (string) Str::uuid(), $f['owner']);

        $return = CustomerReturn::query()->findOrFail($refund->customer_return_id);
        $this->assertSame($return->id, $warranty->refresh()->customer_return_id);
        $this->assertSame($f['order']->id, $return->order_id);
        $this->assertNotNull($return->completed_at);
        $this->assertNull($return->received_at);
        $this->assertDatabaseCount('customer_returns', 1);
        $this->assertDatabaseCount('customer_return_refunds', 1);
        $this->assertSame($inventoryBefore, $this->inventoryState($f['inventory']));
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());

        $this->expectException(ReturnRefundException::class);
        app(ReturnRefundService::class)->recordForWarranty($warranty->refresh(), '200.00', today(), null, null, (string) Str::uuid(), $f['owner']);
    }

    public function test_claim_financial_lifecycle_uses_only_paid_recovery_and_aggregates_multiple_claims(): void
    {
        $f = $this->foundation(2);
        $return = $this->createReturn($f, 2);
        $return = app(ReceiveCustomerReturn::class)->handle($return, $f['owner'])->load('items');
        $item = $return->items->sole();
        app(InspectCustomerReturnItem::class)->handle($item, new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        app(InspectCustomerReturnItem::class)->handle($item, new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        app(ReturnRefundService::class)->recordForReturn($return, '400.00', today(), null, null, (string) Str::uuid(), $f['owner']);
        $claims = SafetClaim::query()->orderBy('id')->get();
        $service = app(SafetClaimService::class);

        foreach ($claims as $index => $claim) {
            $claim = $service->recordClaimedAmount($claim, $index === 0 ? '250.00' : '175.00', $f['owner']);
            $claim = $service->transition($claim, SafetClaimStatus::Filed, $f['owner'], "EXT-{$index}");
            $claim = $service->transition($claim, SafetClaimStatus::InReview, $f['owner']);
            $claim = $service->recordApproval($claim, $index === 0 ? '200.00' : '150.00', $f['owner']);
            if ($index === 0) {
                $claim = $service->recordPayment($claim, '175.00', today(), $f['owner']);
            }
        }

        $financials = app(ReturnFinancialReadService::class);
        $this->assertSame('175.00', $financials->paidRecovery($return->refresh(), $f['owner']));
        $this->assertSame('225.00', $financials->netExposure($return->refresh(), $f['owner']));
        $this->assertSame('175.00', $financials->metrics($f['owner'])['paid_claim_recovery']);
        $this->assertSame(1, $financials->metrics($f['owner'])['returned_orders']);

        $this->expectException(SafetClaimException::class);
        $service->recordPayment($claims->first()->refresh(), '1.00', today(), $f['owner']);
    }

    public function test_financial_permissions_are_additional_to_operational_responsibility_and_queries_omit_fields(): void
    {
        $f = $this->foundation();
        $return = $this->createReturn($f, 1);
        app(ReturnRefundService::class)->recordForReturn($return, '200.00', today(), null, null, (string) Str::uuid(), $f['owner']);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);
        $this->grant($staff, $f['owner'], CustomerReturnPermission::View->value);
        $this->assertFalse(app(CustomerReturnAuthorization::class)->allows($staffUser, CustomerReturnPermission::ViewRefundAmount, $return));
        $this->expectException(AuthorizationException::class);
        app(ReturnFinancialReadService::class)->netExposure($return, $staffUser);
    }

    public function test_financial_overrides_still_require_responsibility_and_protected_sql_is_omitted_without_permission(): void
    {
        $f = $this->foundation();
        $return = $this->createReturn($f, 1);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        foreach ([CustomerReturnPermission::View->value, CustomerReturnPermission::ViewRefundAmount->value, CustomerReturnPermission::RecordRefund->value] as $permission) {
            $this->grant($staff, $f['owner'], $permission);
        }
        $this->assertFalse(app(CustomerReturnAuthorization::class)->allows($staffUser, CustomerReturnPermission::RecordRefund, $return));

        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);
        $this->assertTrue(app(CustomerReturnAuthorization::class)->allows($staffUser, CustomerReturnPermission::RecordRefund, $return));

        $unprivileged = User::factory()->create();
        Employee::factory()->for($unprivileged)->role(EmployeeRole::Admin)->create(['email' => $unprivileged->email]);
        $this->actingAs($unprivileged);
        $returnSql = strtolower(CustomerReturnResource::getEloquentQuery()->toSql());
        $claimSql = strtolower(SafetClaimResource::getEloquentQuery()->toSql());
        foreach (['refund_amount', 'claimed_amount', 'approved_amount', 'reimbursed_amount'] as $field) {
            $this->assertStringNotContainsString($field, $returnSql);
            $this->assertStringNotContainsString($field, $claimSql);
        }
        $this->assertFalse(app(SafetClaimAuthorization::class)->allows($unprivileged, SafetClaimPermission::ViewFinancial));
    }

    public function test_livewire_refund_mutation_is_hidden_without_financial_permission(): void
    {
        $f = $this->foundation();
        $return = $this->createReturn($f, 1);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $this->grant($staff, $f['owner'], CustomerReturnPermission::View->value);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);

        $this->actingAs($staffUser);
        Livewire::test(ViewCustomerReturn::class, ['record' => $return->id])
            ->assertOk()
            ->assertActionHidden('recordRefund');

        $this->expectException(AuthorizationException::class);
        app(ReturnRefundService::class)->recordForReturn($return, '200.00', today(), null, null, (string) Str::uuid(), $staffUser);
    }

    private function foundation(int $quantity = 1): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $platform = MarketplacePlatform::factory()->create(['customer_return_claims_enabled' => true, 'claim_program_name' => 'Safe-T']);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 10, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData($warehouse->id, $platform->id, null, today()->toDateString(), $owner->employee->id, null, [new OrderItemData($product->id, $quantity, '200.00')], (string) Str::uuid()), $owner)->load('fulfillment.items');

        return compact('owner', 'platform', 'product', 'warehouse', 'inventory', 'order');
    }

    private function createReturn(array $f, int $quantity): CustomerReturn
    {
        return app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData(
            $f['order']->id,
            $f['warehouse']->id,
            [['order_fulfillment_item_id' => $f['order']->fulfillment->items->sole()->id, 'quantity' => $quantity, 'return_reason' => CustomerReturnReason::DamagedByCustomer->value]],
            (string) Str::uuid(),
        ), $f['owner']);
    }

    private function createWarranty(array $f, ?CustomerReturn $return = null): WarrantyRepair
    {
        return app(WarrantyRepairService::class)->create([
            'product_id' => $f['product']->id,
            'warehouse_id' => $f['warehouse']->id,
            'marketplace_platform_id' => $f['platform']->id,
            'order_id' => $f['order']->id,
            'customer_return_id' => $return?->id,
            'quantity' => 1,
            'source' => WarrantyRepairSource::Manual->value,
            'issue_description' => 'Customer service case',
            'received_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ], $f['owner']);
    }

    private function inventoryState(ProductInventory $inventory): array
    {
        return $inventory->refresh()->only([
            'available_quantity', 'reserved_quantity', 'damaged_quantity', 'qc_pending_quantity',
            'marketplace_non_sellable_quantity', 'average_cost', 'qc_pending_value', 'marketplace_non_sellable_value',
        ]);
    }

    private function grant(Employee $employee, User $owner, string $permission): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id,
            'permission_key' => $permission,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Focused financial workflow test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($employee->id);
    }
}
