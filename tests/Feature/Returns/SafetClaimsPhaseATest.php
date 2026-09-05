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
use App\Enums\CustomerReturnReason;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\SafetClaimStatus;
use App\Exceptions\CustomerReturnException;
use App\Exceptions\SafetClaimException;
use App\Filament\Resources\SafetClaims\Pages\ListSafetClaims;
use App\Filament\Resources\SafetClaims\Pages\ViewSafetClaim;
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
use App\Services\Claims\SafetClaimAssigneeService;
use App\Services\Claims\SafetClaimService;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SafetClaimsPhaseATest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_direct_qc_damage_creates_prefilled_claim_once_and_sellable_does_not(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 2);
        $return = $this->receiveReturn($f, 2);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(1, 1, (string) Str::uuid(), 'Customer impact damage'), $f['owner']);

        $claim = SafetClaim::query()->sole();
        $this->assertMatchesRegularExpression('/^CLM-\d{4}-\d{6}$/', $claim->reference);
        $this->assertSame(SafetClaimStatus::NeedsFiling, $claim->status);
        $this->assertSame($f['platform']->id, $claim->marketplace_platform_id);
        $this->assertSame($return->id, $claim->customer_return_id);
        $this->assertSame($f['order']->id, $claim->order_id);
        $this->assertSame($f['product']->id, $claim->product_id);
        $this->assertSame(1, $claim->quantity);
        $this->assertSame('Safe-T', $claim->claim_program_name);
        $this->assertDatabaseCount('safet_claim_status_events', 1);
        $this->assertDatabaseHas('activity_logs', ['event' => 'claim.created', 'subject_id' => $claim->id]);

        try {
            app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, $claim->idempotency_key), $f['owner']);
            $this->fail('Duplicate QC retry should be rejected.');
        } catch (CustomerReturnException) {
        }
        $this->assertDatabaseCount('safet_claims', 1);

        $sellable = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $sellableReturn = $this->receiveReturn($sellable, 1);
        app(InspectCustomerReturnItem::class)->handle($sellableReturn->items->sole(), new InspectCustomerReturnItemData(1, 0, (string) Str::uuid()), $sellable['owner']);
        $this->assertDatabaseCount('safet_claims', 1);
    }

    public function test_disabled_platform_and_ambiguous_reason_do_not_create_claims(): void
    {
        foreach ([[false, CustomerReturnReason::DamagedByCustomer], [true, CustomerReturnReason::Other], [true, CustomerReturnReason::Defective]] as [$enabled, $reason]) {
            $f = $this->foundation($enabled, $reason, 1);
            $return = $this->receiveReturn($f, 1);
            app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        }
        $this->assertDatabaseCount('damaged_stock_events', 3);
        $this->assertDatabaseCount('safet_claims', 0);
    }

    public function test_claim_lifecycle_requires_external_reference_and_never_changes_inventory_or_movements(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $claim = SafetClaim::query()->sole();
        $service = app(SafetClaimService::class);
        $inventoryBefore = $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $movements = DB::table('stock_movements')->count();

        try {
            $service->transition($claim, SafetClaimStatus::Filed, $f['owner']);
            $this->fail();
        } catch (ValidationException) {
        }
        $claim = $service->recordClaimedAmount($claim, '200.00', $f['owner']);
        $claim = $service->transition($claim, SafetClaimStatus::Filed, $f['owner'], 'SAFE-T-123');
        $claim = $service->transition($claim, SafetClaimStatus::InReview, $f['owner']);
        $claim = $service->recordApproval($claim, '180.00', $f['owner']);
        $claim = $service->recordPayment($claim, '150.00', today(), $f['owner']);
        $claim = $service->transition($claim, SafetClaimStatus::Closed, $f['owner']);
        $this->assertSame(SafetClaimStatus::Closed, $claim->status);
        $this->assertSame($inventoryBefore, $f['inventory']->refresh()->only(array_keys($inventoryBefore)));
        $this->assertSame($movements, DB::table('stock_movements')->count());
        $this->assertDatabaseCount('safet_claim_status_events', 6);
    }

    public function test_not_eligible_path_and_partial_damage_create_one_claim_per_qc_damage_event(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 2);
        $return = $this->receiveReturn($f, 2);
        $item = $return->items->sole();
        app(InspectCustomerReturnItem::class)->handle($item, new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        app(InspectCustomerReturnItem::class)->handle($item, new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $this->assertDatabaseCount('damaged_stock_events', 2);
        $this->assertDatabaseCount('safet_claims', 2);
        $claim = SafetClaim::query()->firstOrFail();
        $claim = app(SafetClaimService::class)->transition($claim, SafetClaimStatus::NotEligible, $f['owner'], reason: 'Not covered by marketplace policy');
        $this->assertSame(SafetClaimStatus::NotEligible, $claim->status);
        $this->assertSame(2, $f['inventory']->refresh()->damaged_quantity);
    }

    public function test_rejected_lifecycle_and_invalid_transition_are_enforced(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $service = app(SafetClaimService::class);
        $claim = SafetClaim::query()->sole();
        try {
            $service->transition($claim, SafetClaimStatus::Approved, $f['owner']);
            $this->fail();
        } catch (SafetClaimException) {
        }
        $claim = $service->transition($claim, SafetClaimStatus::Filed, $f['owner'], 'EXT-REJECT');
        $claim = $service->transition($claim, SafetClaimStatus::InReview, $f['owner']);
        try {
            $service->transition($claim, SafetClaimStatus::Rejected, $f['owner']);
            $this->fail();
        } catch (ValidationException) {
        }
        $claim = $service->transition($claim, SafetClaimStatus::Rejected, $f['owner'], reason: 'Marketplace rejected evidence');
        $claim = $service->transition($claim, SafetClaimStatus::Closed, $f['owner']);
        $this->assertSame(SafetClaimStatus::Closed, $claim->status);
    }

    public function test_claim_reference_is_reserved_before_qc_transaction(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        $probe = new class extends ReferenceSequenceService
        {
            public array $levels = [];

            public function nextStockMovementReference(): string
            {
                $this->levels['movement'] = DB::transactionLevel();

                return 'SM-980001';
            }

            public function nextDamagedStockReference(?int $year = null): string
            {
                $this->levels['damage'] = DB::transactionLevel();

                return 'DMG-2026-980001';
            }

            public function nextSafetClaimReference(?int $year = null): string
            {
                $this->levels['claim'] = DB::transactionLevel();

                return 'CLM-2026-980001';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $ambient = DB::transactionLevel();
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $this->assertSame(['movement' => $ambient, 'damage' => $ambient, 'claim' => $ambient], $probe->levels);
    }

    public function test_claim_queue_defaults_to_needs_filing_and_staff_is_permission_and_responsibility_scoped(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $staffUser = User::factory()->create();
        $staff = Employee::factory()->for($staffUser)->role(EmployeeRole::Staff)->create(['email' => $staffUser->email]);
        $staffUser->refresh();
        $this->actingAs($staffUser);
        Livewire::test(ListSafetClaims::class)->assertForbidden();
        EmployeePermissionOverride::query()->create(['employee_id' => $staff->id, 'permission_key' => 'safet_claim.view', 'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $f['owner']->id, 'reason' => 'Claim queue']);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->id);
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);
        Livewire::test(ListSafetClaims::class)->assertOk()->assertSee(SafetClaim::query()->sole()->reference)->assertSee('Needs Filing');

        $sql = strtolower(SafetClaimResource::getEloquentQuery()->toSql());
        foreach (['average_cost', 'inventory_value', 'cogs', 'profit'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_claim_is_auto_assigned_only_when_exactly_one_active_eligible_employee_matches(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $eligible = $this->eligibleStaff($f);
        $inactive = $this->eligibleStaff($f, false);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);

        $claim = SafetClaim::query()->sole();
        $this->assertSame($eligible->user_id, $claim->assigned_to_user_id);
        $this->assertNotSame($inactive->user_id, $claim->assigned_to_user_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'claim.assigned', 'subject_id' => $claim->id]);
        $this->actingAs($eligible->user);
        Livewire::test(ListSafetClaims::class)->filterTable('assigned_to_me')->assertCanSeeTableRecords([$claim]);

        $second = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $this->eligibleStaff($second);
        $this->eligibleStaff($second);
        $secondReturn = $this->receiveReturn($second, 1);
        app(InspectCustomerReturnItem::class)->handle($secondReturn->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $second['owner']);
        $this->assertNull(SafetClaim::query()->latest('id')->firstOrFail()->assigned_to_user_id);
    }

    public function test_assignment_is_permission_and_responsibility_checked_and_audited_without_granting_access(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $claim = SafetClaim::query()->sole();
        $eligible = $this->eligibleStaff($f);

        $updated = app(SafetClaimAssigneeService::class)->assign($claim, $eligible->user_id, $f['owner']);
        $this->assertSame($eligible->user_id, $updated->assigned_to_user_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'claim.assigned', 'subject_id' => $claim->id]);

        $ineligibleUser = User::factory()->create();
        Employee::factory()->for($ineligibleUser)->role(EmployeeRole::Staff)->create(['email' => $ineligibleUser->email]);
        $this->expectException(SafetClaimException::class);
        app(SafetClaimAssigneeService::class)->assign($claim, $ineligibleUser->id, $f['owner']);
    }

    public function test_inline_claim_actions_use_services_and_preserve_note_reason_timeline(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $claim = SafetClaim::query()->sole();
        $inventory = $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $movements = DB::table('stock_movements')->count();
        $this->actingAs($f['owner']);

        Livewire::test(ListSafetClaims::class)
            ->assertTableColumnExists('assigned_to_user_id')
            ->assertTableColumnExists('external_claim_reference')
            ->assertTableActionExists('file')
            ->callTableAction('recordClaimedAmount', $claim, ['claimed_amount' => '200.00'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('file', $claim, ['external_reference' => 'EXT-INLINE-1', 'notes' => null])
            ->assertHasNoTableActionErrors();
        $claim->refresh();
        $this->assertSame(SafetClaimStatus::Filed, $claim->status);
        $this->assertSame('EXT-INLINE-1', $claim->external_claim_reference);

        Livewire::test(ListSafetClaims::class)->filterTable('queue', null)->callTableAction('review', $claim)->assertHasNoTableActionErrors();
        $claim->refresh();
        Livewire::test(ListSafetClaims::class)->filterTable('queue', null)->callTableAction('approve', $claim, ['approved_amount' => '180.00', 'notes' => 'Approved after review'])->assertHasNoTableActionErrors();
        $claim->refresh();
        Livewire::test(ListSafetClaims::class)->filterTable('queue', null)->callTableAction('paid', $claim, ['reimbursed_amount' => '150.00', 'paid_date' => today()->toDateString(), 'notes' => null])->assertHasNoTableActionErrors();
        $claim->refresh();
        Livewire::test(ListSafetClaims::class)->filterTable('queue', null)->callTableAction('closePaid', $claim, ['notes' => null])->assertHasNoTableActionErrors();

        $this->assertSame(SafetClaimStatus::Closed, $claim->refresh()->status);
        $this->assertDatabaseHas('safet_claim_status_events', ['safet_claim_id' => $claim->id, 'to_status' => SafetClaimStatus::Approved->value, 'reason' => 'Approved after review']);
        Livewire::test(ViewSafetClaim::class, ['record' => $claim->id])->assertSee('Note / Reason');
        $this->assertSame($inventory, $f['inventory']->refresh()->only(array_keys($inventory)));
        $this->assertSame($movements, DB::table('stock_movements')->count());
    }

    public function test_paid_and_closed_claims_with_null_claimed_amount_can_be_backfilled_without_status_or_inventory_changes(): void
    {
        foreach ([SafetClaimStatus::Paid, SafetClaimStatus::Closed] as $status) {
            $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
            $return = $this->receiveReturn($f, 1);
            app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
            $claim = SafetClaim::query()->latest('id')->firstOrFail();
            $claim->forceFill([
                'status' => $status,
                'approved_amount' => '180.00',
                'reimbursed_amount' => '150.00',
                'approved_at' => now()->subDay(),
                'paid_at' => now(),
                'closed_at' => $status === SafetClaimStatus::Closed ? now() : null,
                'claimed_amount' => null,
            ])->save();
            $inventoryBefore = $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'qc_pending_quantity', 'average_cost']);
            $movementsBefore = DB::table('stock_movements')->count();
            $this->actingAs($f['owner']);

            Livewire::test(ViewSafetClaim::class, ['record' => $claim->id])
                ->assertActionVisible('recordClaimedAmount')
                ->callAction('recordClaimedAmount', ['claimed_amount' => '200.00'])
                ->assertHasNoActionErrors();

            $this->assertSame($status, $claim->refresh()->status);
            $this->assertSame('200.00', $claim->claimed_amount);
            $this->assertSame($inventoryBefore, $f['inventory']->refresh()->only(array_keys($inventoryBefore)));
            $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
            $this->assertDatabaseHas('activity_logs', ['event' => 'claim.claimed_amount_recorded', 'subject_id' => $claim->id]);
        }
    }

    public function test_existing_claimed_amount_requires_explicit_reasoned_correction_and_financial_permission(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $claim = SafetClaim::query()->sole();
        $service = app(SafetClaimService::class);
        $claim = $service->recordClaimedAmount($claim, '100.00', $f['owner']);

        try {
            $service->recordClaimedAmount($claim, '120.00', $f['owner']);
            $this->fail('Existing Claimed Amount was overwritten without the correction workflow.');
        } catch (SafetClaimException) {
        }
        try {
            $service->correctClaimedAmount($claim, '120.00', '', $f['owner']);
            $this->fail('A correction without a reason was accepted.');
        } catch (ValidationException) {
        }

        $corrected = $service->correctClaimedAmount($claim, '120.00', 'Marketplace filing amount corrected', $f['owner']);
        $this->assertSame(SafetClaimStatus::NeedsFiling, $corrected->status);
        $this->assertSame('120.00', $corrected->claimed_amount);
        $log = DB::table('activity_logs')->where('event', 'claim.claimed_amount_corrected')->where('subject_id', $claim->id)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Marketplace filing amount corrected', (string) $log->properties);
        $this->assertStringNotContainsString('100.00', (string) $log->properties);
        $this->assertStringNotContainsString('120.00', (string) $log->properties);

        $unauthorized = $this->eligibleStaff($f);
        $claim->forceFill(['claimed_amount' => null])->save();
        $this->expectException(AuthorizationException::class);
        $service->recordClaimedAmount($claim->refresh(), '130.00', $unauthorized->user);
    }

    public function test_normal_approval_requires_claimed_amount_but_historical_paid_data_is_not_rewritten(): void
    {
        $f = $this->foundation(true, CustomerReturnReason::DamagedByCustomer, 1);
        $return = $this->receiveReturn($f, 1);
        app(InspectCustomerReturnItem::class)->handle($return->items->sole(), new InspectCustomerReturnItemData(0, 1, (string) Str::uuid()), $f['owner']);
        $service = app(SafetClaimService::class);
        $claim = SafetClaim::query()->sole();
        $claim = $service->transition($claim, SafetClaimStatus::Filed, $f['owner'], 'EXT-APPROVAL');
        $claim = $service->transition($claim, SafetClaimStatus::InReview, $f['owner']);

        try {
            $service->recordApproval($claim, '180.00', $f['owner']);
            $this->fail('Claim approval without a Claimed Amount was accepted.');
        } catch (SafetClaimException) {
        }
        $this->assertSame(SafetClaimStatus::InReview, $claim->refresh()->status);
        $claim = $service->recordClaimedAmount($claim, '200.00', $f['owner']);
        $claim = $service->recordApproval($claim, '180.00', $f['owner']);
        $this->assertSame(SafetClaimStatus::Approved, $claim->status);
    }

    private function foundation(bool $claimsEnabled, CustomerReturnReason $reason, int $quantity): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $platform = MarketplacePlatform::factory()->create(['customer_return_claims_enabled' => $claimsEnabled, 'claim_program_name' => $claimsEnabled ? 'Safe-T' : null]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 5, 'average_cost' => '100.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData($warehouse->id, $platform->id, null, now()->toDateString(), $owner->employee->id, null, [new OrderItemData($product->id, $quantity, '200.00')], (string) Str::uuid()), $owner)->load('fulfillment.items');

        return compact('owner', 'platform', 'product', 'warehouse', 'inventory', 'order', 'reason');
    }

    private function receiveReturn(array $f, int $quantity): CustomerReturn
    {
        $return = app(CreateCustomerReturn::class)->handle(new CreateCustomerReturnData($f['order']->id, $f['warehouse']->id, [['order_fulfillment_item_id' => $f['order']->fulfillment->items->sole()->id, 'quantity' => $quantity, 'return_reason' => $f['reason']->value]], (string) Str::uuid()), $f['owner']);

        return app(ReceiveCustomerReturn::class)->handle($return, $f['owner'])->load('items');
    }

    private function eligibleStaff(array $f, bool $active = true): Employee
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email, 'status' => $active]);
        foreach (['safet_claim.view', 'safet_claim.file', 'safet_claim.update_status'] as $permission) {
            EmployeePermissionOverride::query()->create([
                'employee_id' => $employee->id,
                'permission_key' => $permission,
                'effect' => EmployeePermissionEffect::Allow,
                'granted_by_user_id' => $f['owner']->id,
                'reason' => 'Claims work',
            ]);
        }
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->id, 'assigned_by_user_id' => $f['owner']->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $f['product']->id]);

        return $employee;
    }
}
