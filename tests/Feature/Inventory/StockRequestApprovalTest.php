<?php

namespace Tests\Feature\Inventory;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\EmployeeRole;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockRequests\Pages\ViewStockRequest;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class StockRequestApprovalTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_requester_is_read_only_and_actual_allocation_holders_determine_mixed_routing(): void
    {
        $f = $this->responsibilityFoundation(10);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $requester = $f['employee']->user;
        $employeeHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Laptop Team', 'status' => true]);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($employeeHolder->employee->id), 5, $f['owner'], 'Employee holder');
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 2, $f['owner'], 'Team holder');
        $inventoryBefore = $f['inventory']->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']);
        $balancesBefore = $this->balanceSnapshot();

        $request = $this->createRequest($requester, [[$f['inventory']->id, 8]]);
        $lines = $request->sourceLines()->with('account')->orderBy('id')->get();

        $this->assertSame($requester->employee->id, $request->requested_by_employee_id);
        $this->assertSame(['employee', 'team', 'system'], $lines->pluck('source_type')->all());
        $this->assertSame([5, 2, 1], $lines->pluck('proposed_quantity')->all());
        $this->assertSame($employeeHolder->employee->id, $lines[0]->account->employee_id);
        $this->assertNotSame($requester->employee->id, $lines[0]->account->employee_id);
        $this->assertTrue($lines[2]->account->is_system);
        $this->assertFalse(app(StockRequestApprovalService::class)->canDecide($requester, $lines[0]));
        $this->assertSame($inventoryBefore, $f['inventory']->refresh()->only(['available_quantity', 'reserved_quantity', 'damaged_quantity']));
        $this->assertSame($balancesBefore, $this->balanceSnapshot());

        $this->actingAs($requester);
        Livewire::test(CreateStockRequest::class)
            ->assertSee('Requested By')
            ->assertSee($requester->employee->employee_id)
            ->assertDontSee('Source Holder');

        Livewire::test(ViewStockRequest::class, ['record' => $request->id])
            ->assertSuccessful()
            ->assertSee('Available Sources / Approval Status')
            ->assertSee('System / Unassigned Stock')
            ->assertSee('Owner/Admin');
    }

    public function test_employee_holder_can_decide_only_own_line_and_can_see_routed_request(): void
    {
        $f = $this->responsibilityFoundation(6);
        $firstHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $secondHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($firstHolder->employee->id), 2, $f['owner'], 'First holder');
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($secondHolder->employee->id), 2, $f['owner'], 'Second holder');
        $request = $this->createRequest($f['owner'], [[$f['inventory']->id, 4]]);
        $firstLine = $request->sourceLines()->whereHas('account', fn ($query) => $query->where('employee_id', $firstHolder->employee->id))->firstOrFail();
        $secondLine = $request->sourceLines()->whereHas('account', fn ($query) => $query->where('employee_id', $secondHolder->employee->id))->firstOrFail();
        $approvals = app(StockRequestApprovalService::class);

        $this->assertTrue(app(StockRequestService::class)->visibleQuery($firstHolder)->whereKey($request)->exists());
        $this->assertTrue($approvals->canDecide($firstHolder, $firstLine));
        $this->assertFalse($approvals->canDecide($firstHolder, $secondLine));

        $approvals->decide($firstLine, StockRequestSourceStatus::Approved, 'Approved for transfer', (string) Str::uuid(), $firstHolder);
        $this->assertSame(StockRequestStatus::PartiallyApproved, $request->refresh()->status);

        $this->expectException(AuthorizationException::class);
        $approvals->decide($secondLine, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $firstHolder);
    }

    public function test_team_and_system_sources_require_owner_or_admin_and_full_approval_aggregates(): void
    {
        $f = $this->responsibilityFoundation(8);
        $team = Team::query()->create(['name' => 'Sales Team', 'status' => true]);
        $teamMember = $this->responsibilityUser(EmployeeRole::Staff, $team);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->teamAccount($team->id), 3, $f['owner'], 'Team holder');
        $request = $this->createRequest($f['owner'], [[$f['inventory']->id, 5]]);
        $teamLine = $request->sourceLines()->where('source_type', 'team')->firstOrFail();
        $systemLine = $request->sourceLines()->where('source_type', 'system')->firstOrFail();
        $approvals = app(StockRequestApprovalService::class);

        $this->assertFalse($approvals->canDecide($teamMember, $teamLine));
        $this->assertFalse($approvals->canDecide($teamMember, $systemLine));
        $this->assertTrue($approvals->canDecide($admin, $teamLine));
        $this->assertTrue($approvals->canDecide($admin, $systemLine));

        $approvals->decide($teamLine, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $admin);
        $this->assertSame(StockRequestStatus::PartiallyApproved, $request->refresh()->status);
        $approvals->decide($systemLine, StockRequestSourceStatus::Approved, 'Owner/Admin approved unassigned stock', (string) Str::uuid(), $admin);

        $this->assertSame(StockRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame($admin->id, $systemLine->refresh()->decided_by_user_id);
        $this->assertNotNull($systemLine->decided_at);
    }

    public function test_reject_requires_reason_rejects_request_and_decisions_are_idempotent_and_immutable(): void
    {
        $f = $this->responsibilityFoundation(4);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $this->allocate($f['inventory'], $holder->employee, 2, $f['owner']);
        $request = $this->createRequest($f['owner'], [[$f['inventory']->id, 2]]);
        $line = $request->sourceLines()->firstOrFail();
        $approvals = app(StockRequestApprovalService::class);

        try {
            $approvals->decide($line, StockRequestSourceStatus::Rejected, null, (string) Str::uuid(), $holder);
            $this->fail('Rejection without a reason must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame('Please enter a reason for rejecting this request.', $exception->errors()['note'][0]);
        }

        $key = (string) Str::uuid();
        $first = $approvals->decide($line, StockRequestSourceStatus::Rejected, 'Required elsewhere', $key, $holder);
        $second = $approvals->decide($line, StockRequestSourceStatus::Rejected, 'Required elsewhere', $key, $holder);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(StockRequestStatus::Rejected, $request->refresh()->status);
        $this->assertDatabaseCount('stock_request_source_lines', 1);

        $this->expectException(ValidationException::class);
        $approvals->decide($line, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $f['owner']);
    }

    public function test_approval_revalidates_employee_and_system_availability_with_readable_errors(): void
    {
        $f = $this->responsibilityFoundation(8);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $this->allocate($f['inventory'], $holder->employee, 5, $f['owner']);
        $request = $this->createRequest($f['owner'], [[$f['inventory']->id, 7]]);
        $employeeLine = $request->sourceLines()->where('source_type', 'employee')->firstOrFail();
        $systemLine = $request->sourceLines()->where('source_type', 'system')->firstOrFail();
        $approvals = app(StockRequestApprovalService::class);

        InventoryAllocationBalance::query()->where('account_id', $employeeLine->inventory_allocation_account_id)
            ->where('product_inventory_id', $f['inventory']->id)->update(['allocated_quantity' => 3]);
        try {
            $approvals->decide($employeeLine, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $holder);
            $this->fail('Stale employee availability must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame("{$employeeLine->source_label} currently has only 3 units available; this approval requires 5.", $exception->errors()['source_line_id'][0]);
        }

        InventoryAllocationBalance::query()->where('account_id', $systemLine->inventory_allocation_account_id)
            ->where('product_inventory_id', $f['inventory']->id)->update(['allocated_quantity' => 1]);
        try {
            $approvals->decide($systemLine, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $f['owner']);
            $this->fail('Stale System availability must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame('Only 1 unassigned unit is currently available; this approval requires 2.', $exception->errors()['source_line_id'][0]);
        }

        $this->assertSame(StockRequestStatus::Pending, $request->refresh()->status);
    }

    public function test_multi_product_approval_changes_only_workflow_records(): void
    {
        $f = $this->responsibilityFoundation(6);
        $second = ProductInventory::factory()->create([
            'product_id' => Product::factory(),
            'warehouse_id' => $f['inventory']->warehouse_id,
            'available_quantity' => 5,
        ]);
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $this->allocate($f['inventory'], $holder->employee, 3, $f['owner']);
        $this->allocate($second, $holder->employee, 2, $f['owner']);
        $inventoryBefore = ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])->all();
        $balancesBefore = $this->balanceSnapshot();
        $responsibilitiesBefore = DB::table('responsibility_assignments')->orderBy('id')->get()->toArray();
        $ordersBefore = DB::table('orders')->orderBy('id')->get()->toArray();
        $request = $this->createRequest($f['owner'], [[$f['inventory']->id, 2], [$second->id, 2]]);

        foreach ($request->sourceLines()->get() as $line) {
            app(StockRequestApprovalService::class)->decide($line, StockRequestSourceStatus::Approved, null, (string) Str::uuid(), $f['owner']);
        }

        $this->assertSame(StockRequestStatus::Approved, $request->refresh()->status);
        $this->assertSame($inventoryBefore, ProductInventory::query()->orderBy('id')->get()->map->only(['id', 'available_quantity', 'reserved_quantity', 'damaged_quantity'])->all());
        $this->assertSame($balancesBefore, $this->balanceSnapshot());
        $this->assertEquals($responsibilitiesBefore, DB::table('responsibility_assignments')->orderBy('id')->get()->toArray());
        $this->assertEquals($ordersBefore, DB::table('orders')->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('order_fulfillments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function createRequest(User $actor, array $items): StockRequest
    {
        return app(StockRequestService::class)->create(new CreateStockRequestData(
            purpose: StockRequestPurpose::PermanentTransfer,
            orderId: null,
            items: collect($items)->map(fn (array $item): StockRequestItemData => new StockRequestItemData($item[0], $item[1]))->all(),
            reason: 'Stock required for operational work',
            idempotencyKey: (string) Str::uuid(),
        ), $actor);
    }

    private function allocate(ProductInventory $inventory, Employee $employee, int $quantity, User $owner): void
    {
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $service->reconcile($inventory, $service->employeeAccount($employee->id), $quantity, $owner, 'F2 test allocation');
    }

    private function balanceSnapshot(): array
    {
        return InventoryAllocationBalance::query()->orderBy('id')->get()
            ->map->only(['id', 'account_id', 'product_inventory_id', 'allocated_quantity', 'reserved_quantity'])->all();
    }
}
