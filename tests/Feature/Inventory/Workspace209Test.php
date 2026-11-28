<?php

namespace Tests\Feature\Inventory;

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Exceptions\InventoryInvariantException;
use App\Filament\Pages\SalesWorkspace;
use App\Livewire\WorkspaceReservations;
use App\Livewire\WorkspaceStockTable;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Orders\ExternalOrderIdentityService;
use App\Services\Orders\OrderReservationReleaseService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Orders\OrderService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\Responsibilities\ResponsibilityStockTransitionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class Workspace209Test extends TestCase
{
    use RefreshDatabase, ResponsibilityTestFoundation;

    public function test_location_table_wraps_full_titles_filters_and_does_not_double_count_allocations(): void
    {
        $f = $this->responsibilityFoundation(10, 2);
        $f['product']->update(['name' => 'HP Series 5 527sf Complete Catalog Title with long model ABC-1234567890']);
        $this->balance($f['employee']->id, $f['inventory'], 6, 1);
        $this->actingAs($f['owner']);
        Livewire::test(WorkspaceStockTable::class)->assertSee($f['product']->name)->assertSee('ABC-1234567890')
            ->assertViewHas('summary', fn ($s) => $s['held'] === 10 && $s['reserved'] === 2 && $s['sellable'] === 8)
            ->set('search', 'not-a-product')->call('applyFilters')->assertViewHas('summary', fn ($s) => $s['rows'] === 0)
            ->call('resetFilters')->assertSee($f['product']->sku)->call('details', $f['inventory']->id)->assertSee('Stock breakdown');
    }

    public function test_my_inventory_is_personal_and_cost_denial_hides_values_and_history(): void
    {
        $f = $this->responsibilityFoundation(10, 2);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $this->balance($f['employee']->id, $f['inventory'], 4, 1);
        EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => 'inventory.view', 'effect' => 'allow', 'granted_by_user_id' => $f['owner']->id, 'reason' => 'Scoped inventory view fixture']);
        foreach (['inventory.view_financials', 'purchase.view_cost_history'] as $key) {
            EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => $key, 'effect' => 'deny', 'granted_by_user_id' => $f['owner']->id, 'reason' => 'Privacy fixture']);
        }
        $this->actingAs($f['employee']->user);
        Livewire::test(WorkspaceStockTable::class, ['mode' => 'mine'])->assertViewHas('summary', fn ($s) => $s['held'] === 4 && $s['sellable'] === 3)
            ->assertDontSee('Average cost / value')->assertDontSee('125.00')->call('purchaseHistory', $f['inventory']->id)->assertForbidden();
    }

    public function test_unrelated_inventory_cannot_be_opened_by_a_forged_id(): void
    {
        $f = $this->responsibilityFoundation();
        EmployeePermissionOverride::query()->create(['employee_id' => $f['employee']->id, 'permission_key' => 'inventory.view', 'effect' => 'allow', 'granted_by_user_id' => $f['owner']->id, 'reason' => 'Scoped inventory view fixture']);
        $this->actingAs($f['employee']->user);
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(WorkspaceStockTable::class)->call('details', $f['inventory']->id);
    }

    public function test_complete_order_release_is_idempotent_preserves_physical_stock_and_can_be_reserved_again(): void
    {
        [$f,$order] = $this->reserved();
        $original = $order->items()->first()->reservation;
        $key = (string) Str::uuid();
        $service = app(OrderReservationReleaseService::class);
        $service->release($order, $f['owner'], 'Customer requested hold release', $key);
        $service->release($order->fresh(), $f['owner'], 'Customer requested hold release', $key);
        $this->assertSame(OrderStatus::Draft, $order->fresh()->status);
        $this->assertSame(10, $f['inventory']->fresh()->available_quantity);
        $this->assertSame(0, $f['inventory']->fresh()->reserved_quantity);
        $this->assertSame('released', $original->fresh()->status->value);
        $this->assertSame(1, StockMovement::query()->where('source_id', $original->id)->where('reserved_delta', '<', 0)->count());
        app(OrderService::class)->reserveDraft($order->fresh(), $f['owner']);
        $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
        $latest = $order->fresh()->items()->first()->reservation;
        $this->assertNotSame($original->id, $latest->id);
        $this->assertSame('active', $latest->status->value);
    }

    public function test_release_denial_and_transfer_hold_cannot_be_bypassed(): void
    {
        [$f,$order] = $this->reserved();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $this->actingAs($other);
        Livewire::test(WorkspaceReservations::class)->call('prepareRelease', $order->items()->first()->reservation->id)->assertNotFound();
        $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
        $this->actingAs($f['owner']);
        $standalone = InventoryReservation::factory()->create(['product_inventory_id' => $f['inventory']->id, 'product_id' => $f['product']->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'order_item_id' => null]);
        Livewire::test(WorkspaceReservations::class)->call('prepareRelease', $standalone->id)->assertForbidden();
    }

    public function test_release_requires_reason_and_does_not_release_fulfilled_orders(): void
    {
        [$f,$order] = $this->reserved();
        $this->actingAs($f['owner']);
        Livewire::test(WorkspaceReservations::class)->call('prepareRelease', $order->items()->first()->reservation->id)->call('confirmRelease')->assertHasErrors('reason');
        $order->update(['status' => OrderStatus::Fulfilled]);
        $this->assertFalse(app(OrderReservationReleaseService::class)->allows($f['owner'], $order));
        $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
    }

    public function test_brand_handoff_transfers_current_ledger_and_zero_stock_scope_without_touching_history(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        $zero = Product::factory()->create(['brand_id' => $f['brand']->id]);
        $source = app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $this->balance($f['employee']->id, $f['inventory'], 10, 0);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $historical = app(OrderService::class)->saveDraft(new SaveAndReserveOrderData($f['inventory']->warehouse_id, null, null, '2026-10-01', $f['employee']->id, 'Historical actor attribution fixture', [new OrderItemData($f['product']->id, 1, '150.00')], (string) Str::uuid()), $f['owner']);
        $before = $historical->fresh()->toArray();
        $service = app(ResponsibilityStockTransitionService::class);
        $plan = $service->preview($source, $f['owner']);
        $data = new TransferResponsibilityAssignmentData($destination->employee->id, 'Owner reviewed brand handoff', (string) Str::uuid());
        $next = $service->transfer($source, $data, $f['owner'], $plan);
        $again = $service->transfer($source, $data, $f['owner'], $plan);
        $this->assertSame($next->id, $again->id);
        $this->assertSame(10, $f['inventory']->fresh()->available_quantity);
        $this->assertSame(0, app(InventoryAllocationService::class)->employeeAccount($f['employee']->id)->balances()->sum('allocated_quantity'));
        $this->assertSame(10, (int) app(InventoryAllocationService::class)->employeeAccount($destination->employee->id)->balances()->sum('allocated_quantity'));
        $this->assertTrue(app(ResponsibilityProductScopeService::class)->canAccessProduct($destination, $zero->id));
        $this->assertFalse(app(ResponsibilityProductScopeService::class)->canAccessProduct($f['employee']->user, $zero->id));
        $this->assertFalse(app(OrderResponsibilityScopeService::class)->canAccessProduct($f['employee']->user, $f['product']->id, null, $f['inventory']->warehouse_id));
        $this->assertTrue(app(OrderResponsibilityScopeService::class)->canAccessProduct($destination, $f['product']->id, null, $f['inventory']->warehouse_id));
        $this->assertSame(1, InventoryAllocationEvent::query()->where('event_type', 'responsibility_stock_transfer')->count());
        $this->assertSame($data->reason, InventoryAllocationEvent::query()->where('event_type', 'responsibility_stock_transfer')->sole()->reason);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($before, $historical->fresh()->toArray());
    }

    public function test_a_failure_on_the_second_release_line_rolls_back_the_first_line(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        $other = Product::factory()->create();
        $otherInventory = ProductInventory::factory()->create(['product_id' => $other->id, 'warehouse_id' => $f['inventory']->warehouse_id, 'available_quantity' => 10, 'reserved_quantity' => 0]);
        $data = new SaveAndReserveOrderData($f['inventory']->warehouse_id, null, null, today()->toDateString(), $f['owner']->employee->id, null, [new OrderItemData($f['product']->id, 2, '150.00'), new OrderItemData($other->id, 1, '150.00')], (string) Str::uuid());
        $order = app(SaveAndReserveOrder::class)->handle($data, $f['owner']);
        $otherInventory->refresh()->update(['reserved_quantity' => 0]); // Disposable inconsistent fixture only.
        try {
            app(OrderReservationReleaseService::class)->release($order, $f['owner'], 'Atomic rollback fixture', (string) Str::uuid());
            $this->fail('Inconsistent second line should fail');
        } catch (InventoryInvariantException) {
            $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
            $this->assertSame('reserved', $order->fresh()->status->value);
            $this->assertSame(0, StockMovement::query()->where('reserved_delta', '<', 0)->count());
            $this->assertSame(2, InventoryReservation::query()->whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id))->where('status', 'active')->count());
        }
    }

    public function test_inline_duplicate_warning_preserves_scope_and_marketplace_identity(): void
    {
        [$f, $order] = $this->reserved();
        $platform = MarketplacePlatform::factory()->create();
        $identity = app(ExternalOrderIdentityService::class);
        $order->update(['marketplace_platform_id' => $platform->id, 'external_order_number' => ' EXTERNAL-209 ', 'external_identity_hash' => $identity->hash($platform->id, 'EXTERNAL-209')]);
        $this->assertStringContainsString($order->reference, $identity->warning($platform->id, 'external-209', $f['owner']));
        $this->assertNull($identity->warning($platform->id, 'external-209', $f['owner'], $order->id));
        $this->assertNull($identity->warning($platform->id + 1000, 'external-209', $f['owner']));
        $this->assertNull($identity->warning(null, 'external-209', $f['owner']));
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $this->assertSame('This Platform Order Number already exists.', $identity->warning($platform->id, 'external-209', $staff));
        $this->assertNull($identity->duplicateNotice($platform->id, 'external-209', $staff)['order']);
        $this->assertSame($order->id, $identity->duplicateNotice($platform->id, 'external-209', $f['owner'])['order']->id);
    }

    public function test_real_sales_create_modal_displays_duplicate_and_rejects_submit_without_stock_changes(): void
    {
        [$f, $order] = $this->reserved();
        $platform = $f['platform'];
        $order->update(['marketplace_platform_id' => $platform->id, 'external_order_number' => 'ACTUAL-MODAL-209', 'external_identity_hash' => app(ExternalOrderIdentityService::class)->hash($platform->id, 'ACTUAL-MODAL-209')]);
        $this->actingAs($f['owner']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(NavigationPreferenceService::class)->setWorkspaceMode($f['owner'], true);
        $component = Livewire::test(SalesWorkspace::class)->mountAction('createSalesOrder')->assertActionMounted('createSalesOrder')
            ->fillForm([
                'warehouse_id' => $f['inventory']->warehouse_id, 'marketplace_platform_id' => $platform->id,
                'external_order_number' => 'actual-modal-209', 'order_date' => today()->toDateString(),
                'handled_by_employee_id' => $f['owner']->employee->id, 'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $f['product']->id, 'quantity' => 1, 'selling_price' => '150.00', 'discount_total' => '0.00', 'vat_rate' => '0.0000']],
            ]);
        $notice = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('Duplicate Order Detected', $notice);
        $this->assertStringContainsString($order->reference, $notice);
        $component->callMountedAction(arguments: ['mode' => 'draft'])->assertHasActionErrors(['external_order_number']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
    }

    public function test_reservation_filters_match_order_reference_and_reset_without_mutations(): void
    {
        [$f, $order] = $this->reserved();
        $this->actingAs($f['owner']);
        Livewire::test(WorkspaceReservations::class)->set('search', $order->reference)->call('applyFilters')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
            ->set('status', 'released')->call('applyFilters')->assertViewHas('rows', fn ($rows) => $rows->total() === 0)
            ->call('resetFilters')->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->assertSame(2, $f['inventory']->fresh()->reserved_quantity);
    }

    public function test_quick_view_has_scoped_locations_and_reserved_section_without_financial_leakage(): void
    {
        $f = $this->responsibilityFoundation(10, 0);
        $this->actingAs($f['owner']);
        Livewire::test(WorkspaceStockTable::class)->call('details', $f['inventory']->id, 'reservations')
            ->assertSet('detailTab', 'reservations')->assertSee('Transfer holds and employee allocations are not released here')
            ->call('detailSection', 'allocation')->assertViewHas('detailLocations', fn ($rows) => $rows->modelKeys() === [$f['inventory']->id]);
        Livewire::test(WorkspaceStockTable::class)->call('details', $f['inventory']->id, 'unexpected')->assertNotFound();
    }

    public function test_unapproved_actor_cannot_move_brand_scope_or_allocations(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $this->expectException(HttpException::class);
        app(ResponsibilityStockTransitionService::class)->preview($source, $f['employee']->user);
    }

    public function test_reserved_brand_stock_blocks_the_entire_handoff_and_preserves_source(): void
    {
        $f = $this->responsibilityFoundation(10, 2);
        $source = app(ResponsibilityAssignmentService::class)->create($this->assignmentData($f), $f['owner']);
        $this->balance($f['employee']->id, $f['inventory'], 10, 2);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $service = app(ResponsibilityStockTransitionService::class);
        try {
            $service->transfer($source, new TransferResponsibilityAssignmentData($destination->employee->id, 'Blocked fixture', (string) Str::uuid()), $f['owner'], $service->preview($source, $f['owner']));
            $this->fail('Blocked stock moved');
        } catch (ValidationException) {
            $this->assertSame('active', $source->fresh()->status->value);
            $this->assertSame(10, (int) app(InventoryAllocationService::class)->employeeAccount($f['employee']->id)->balances()->sum('allocated_quantity'));
        }
    }

    private function reserved(): array
    {
        $f = $this->responsibilityFoundation(10, 0);
        $data = new SaveAndReserveOrderData($f['inventory']->warehouse_id, null, null, today()->toDateString(), $f['owner']->employee->id, null, [new OrderItemData($f['product']->id, 2, '150.00')], (string) Str::uuid());

        return [$f, app(SaveAndReserveOrder::class)->handle($data, $f['owner'])];
    }

    private function balance(int $employeeId, ProductInventory $inventory, int $held, int $reserved): void
    {
        $account = app(InventoryAllocationService::class)->employeeAccount($employeeId);
        InventoryAllocationBalance::query()->create(['account_id' => $account->id, 'product_inventory_id' => $inventory->id, 'allocated_quantity' => $held, 'reserved_quantity' => $reserved]);
    }
}
