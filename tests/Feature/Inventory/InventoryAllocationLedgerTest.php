<?php

namespace Tests\Feature\Inventory;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Inventory\PostOpeningStockData;
use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use App\Enums\InventoryPermission;
use App\Exceptions\ImmutableInventoryRecordException;
use App\Exceptions\InventoryInvariantException;
use App\Filament\Pages\Administration\InventoryAllocations;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationEvent;
use App\Models\InventoryAllocationReservationLine;
use App\Models\InventoryAllocationRule;
use App\Models\InventoryAllocationSetting;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryService;
use App\Services\Purchases\PurchaseReceivingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryAllocationLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_uses_explicit_mysql_safe_identifier_names(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_23_090000_create_inventory_allocation_ledger.php'));

        $identifiers = [
            'iaa_identity_uq',
            'iaa_type_idx',
            'iaa_employee_uq',
            'iaa_employee_fk',
            'iaa_team_uq',
            'iaa_team_fk',
            'iaa_system_idx',
            'iaa_status_idx',
            'ias_singleton_uq',
            'ias_updated_by_fk',
            'ialr_target_fk',
            'ialr_product_fk',
            'ialr_brand_fk',
            'ialr_category_fk',
            'ialr_warehouse_fk',
            'ialr_priority_idx',
            'ialr_status_idx',
            'ialr_created_by_fk',
            'iab_account_fk',
            'iab_inventory_fk',
            'iab_account_inventory_uq',
            'iae_event_key_uq',
            'iae_event_type_idx',
            'iae_inventory_fk',
            'iae_from_account_fk',
            'iae_to_account_fk',
            'iae_source_idx',
            'iae_order_fk',
            'iae_receipt_fk',
            'iae_actor_fk',
            'iae_inventory_created_idx',
            'iarl_reservation_fk',
            'iarl_account_fk',
            'iarl_status_idx',
            'iarl_reservation_account_uq',
            'pral_receipt_item_fk',
            'pral_account_fk',
            'pral_receipt_account_uq',
            'iatl_context_type_idx',
            'iatl_context_id_idx',
            'iatl_account_fk',
            'iatl_source_inventory_fk',
            'iatl_destination_inventory_fk',
            'iatl_order_item_fk',
            'iatl_status_idx',
            'iatl_context_account_inventory_uq',
            'iaa_identity_chk',
            'iab_values_chk',
            'iaa_identity_bi',
            'iaa_identity_bu',
            'iab_values_bi',
            'iab_values_bu',
        ];

        foreach ($identifiers as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier), $identifier);
            $this->assertStringContainsString($identifier, $migration);
        }

        $this->assertStringNotContainsString('->constrained(', $migration);
        $this->assertDoesNotMatchRegularExpression('/->(?:unique|index)\(\s*\)/', $migration);
    }

    public function test_migration_reconciles_legacy_stock_and_preserves_active_reservations_in_system_account(): void
    {
        $migration = require database_path('migrations/2026_09_23_090000_create_inventory_allocation_ledger.php');
        $migration->down();

        [$owner, $product, $warehouse, $inventory] = $this->foundation(10);
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $inventory->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
            'status' => 'active',
        ]);
        $inventory->forceFill(['reserved_quantity' => 3])->save();

        $migration->up();

        $system = InventoryAllocationAccount::query()->where('is_system', true)->sole();
        $this->assertDatabaseHas('inventory_allocation_settings', [
            'id' => 1,
            'enforcement_mode' => InventoryAllocationMode::MigrationShadow->value,
        ]);
        $this->assertDatabaseHas('inventory_allocation_balances', [
            'account_id' => $system->id,
            'product_inventory_id' => $inventory->id,
            'allocated_quantity' => 10,
            'reserved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('inventory_allocation_reservation_lines', [
            'inventory_reservation_id' => $reservation->id,
            'account_id' => $system->id,
            'quantity' => 3,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('inventory_allocation_events', [
            'event_type' => 'legacy_reconciliation',
            'product_inventory_id' => $inventory->id,
            'quantity' => 10,
        ]);
        $this->assertSame('active', $reservation->refresh()->status->value);
        $this->assertSame(3, $inventory->refresh()->reserved_quantity);
    }

    public function test_shadow_mode_preserves_legacy_order_reservation_release_and_fulfilment_with_system_audit(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(10);
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($owner, $product, $warehouse, 3), $owner);
        $system = app(InventoryAllocationService::class)->systemAccount();
        $balance = InventoryAllocationBalance::query()->where('account_id', $system->id)->where('product_inventory_id', $inventory->id)->sole();
        $this->assertSame(10, $balance->allocated_quantity);
        $this->assertSame(3, $balance->reserved_quantity);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'legacy_system_reservation', 'quantity' => 3]);

        app(CancelOrder::class)->handle($order, new CancelOrderData('Customer cancelled', (string) Str::uuid()), $owner);
        $this->assertSame(0, $balance->refresh()->reserved_quantity);
        $this->assertSame(10, $balance->allocated_quantity);

        $other = app(SaveAndReserveOrder::class)->handle($this->orderData($owner, $product, $warehouse, 2), $owner);
        app(FulfillOrder::class)->handle($other, (string) Str::uuid(), $owner);
        $this->assertSame(8, $balance->refresh()->allocated_quantity);
        $this->assertSame(0, $balance->reserved_quantity);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'fulfilment_consumption', 'quantity' => 2]);
    }

    public function test_strict_mode_blocks_system_stock_until_explicit_reconciliation_and_quantity_responsibility_is_not_ownership(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(5);
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        ResponsibilityAssignment::factory()->create(['employee_id' => $owner->employee->id, 'assigned_by_user_id' => $owner->id]);
        $this->assertDatabaseCount('inventory_allocation_accounts', 1);

        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
        try {
            app(SaveAndReserveOrder::class)->handle($this->orderData($owner, $product, $warehouse, 1), $owner);
            $this->fail('Strict mode must not consume System / Unallocated stock.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
        }

        $employeeAccount = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        app(InventoryAllocationService::class)->reconcile($inventory, $employeeAccount, 2, $owner, 'Owner allocation reconciliation');
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($owner, $product, $warehouse, 2), $owner);
        $this->assertNotNull($order->id);
        $this->assertDatabaseHas('inventory_allocation_reservation_lines', ['account_id' => $employeeAccount->id, 'quantity' => 2, 'status' => 'reserved']);
    }

    public function test_one_order_reservation_can_be_attributed_to_multiple_allocation_accounts(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(4);
        $team = Team::query()->create(['name' => 'Allocation Team', 'status' => true]);
        $owner->employee->forceFill(['team_id' => $team->id])->save();
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $employeeAccount = $service->employeeAccount($owner->employee->id);
        $teamAccount = $service->teamAccount($team->id);
        $service->reconcile($inventory, $employeeAccount, 1, $owner, 'Employee allocation');
        $service->reconcile($inventory, $teamAccount, 1, $owner, 'Team allocation');

        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
        $order = app(SaveAndReserveOrder::class)->handle($this->orderData($owner, $product, $warehouse, 2), $owner);
        $reservationId = $order->items->first()->reservation->id;

        $this->assertDatabaseHas('inventory_allocation_reservation_lines', [
            'inventory_reservation_id' => $reservationId,
            'account_id' => $employeeAccount->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('inventory_allocation_reservation_lines', [
            'inventory_reservation_id' => $reservationId,
            'account_id' => $teamAccount->id,
            'quantity' => 1,
        ]);
    }

    public function test_grn_policies_allocate_accepted_stock_without_changing_costing(): void
    {
        [$owner, $purchase] = $this->approvedPurchase(6, '25.0000');
        $line = $purchase->items->firstOrFail();
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        InventoryAllocationSetting::query()->whereKey(1)->update(['default_policy' => InventoryAllocationPolicy::AskAtGrn->value]);
        $receipt = app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 2, 0, 0, null, $account->id)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner);
        $inventory = ProductInventory::query()->sole();
        $this->assertSame('25.0000', $inventory->average_cost);
        $this->assertDatabaseHas('purchase_receipt_allocation_lines', ['purchase_receipt_item_id' => $receipt->items->first()->id, 'account_id' => $account->id, 'quantity' => 2, 'allocation_method' => 'grn_selected']);

        [$owner2, $purchase2] = $this->approvedPurchase(2, '40.0000', $owner);
        $line2 = $purchase2->items->firstOrFail();
        InventoryAllocationSetting::query()->whereKey(1)->update(['default_policy' => InventoryAllocationPolicy::Automatic->value]);
        InventoryAllocationRule::query()->create(['name' => 'Product owner', 'target_account_id' => $account->id,
            'product_id' => $line2->product_id, 'priority' => 1, 'status' => true, 'created_by_user_id' => $owner->id]);
        app(PurchaseReceivingService::class)->receive($purchase2, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line2->id, 2, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()
        ), $owner2);
        $this->assertDatabaseHas('purchase_receipt_allocation_lines', ['account_id' => $account->id, 'quantity' => 2, 'allocation_method' => 'automatic_rule']);

        InventoryAllocationSetting::query()->whereKey(1)->update([
            'enforcement_mode' => InventoryAllocationMode::Strict->value,
            'default_policy' => InventoryAllocationPolicy::NoAutomatic->value,
        ]);
        [$owner3, $purchase3] = $this->approvedPurchase(1, '50.0000', $owner);
        $line3 = $purchase3->items->firstOrFail();
        try {
            app(PurchaseReceivingService::class)->receive($purchase3, new ReceivePurchaseData(
                [new PurchaseReceiptItemData($line3->id, 1, 0, 0)], now()->toDateTimeString(), (string) Str::uuid()
            ), $owner3);
            $this->fail('Strict mode must reject a GRN when automatic allocation is disabled.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('No automatic allocation', $exception->getMessage());
            $this->assertSame(0, $line3->refresh()->received_quantity);
        }
    }

    public function test_strict_grn_requires_a_non_system_explicit_account_and_blocks_unmatched_automatic_policy(): void
    {
        [$owner, , , $inventory] = $this->foundation(0);
        $policy = app(InventoryAllocationPolicyService::class);
        $system = app(InventoryAllocationService::class)->systemAccount();
        InventoryAllocationSetting::query()->whereKey(1)->update([
            'enforcement_mode' => InventoryAllocationMode::Strict->value,
            'default_policy' => InventoryAllocationPolicy::AskAtGrn->value,
        ]);

        foreach ([null, $system->id] as $selection) {
            try {
                $policy->receiptAccount($inventory->load('product'), $selection);
                $this->fail('Strict Ask at GRN must require a non-System account.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        InventoryAllocationSetting::query()->whereKey(1)->update(['default_policy' => InventoryAllocationPolicy::Automatic->value]);
        $this->expectException(ValidationException::class);
        $policy->receiptAccount($inventory->load('product'), null);
    }

    public function test_balance_invariants_reject_reserved_overrun_and_duplicate_singletons(): void
    {
        [$owner, , , $inventory] = $this->foundation(2);
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $system = $service->systemAccount();

        try {
            InventoryAllocationBalance::query()->where('account_id', $system->id)
                ->where('product_inventory_id', $inventory->id)->update(['reserved_quantity' => 3]);
            $this->fail('Database invariant must reject reserved allocation above allocated allocation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            fn () => InventoryAllocationAccount::query()->create(['identity_key' => 'system', 'type' => 'system', 'name' => 'Duplicate', 'is_system' => true, 'status' => true]),
            fn () => InventoryAllocationSetting::query()->create(['singleton_key' => 'inventory_allocation', 'enforcement_mode' => 'migration_shadow', 'default_policy' => 'no_automatic']),
        ] as $duplicate) {
            try {
                $duplicate();
                $this->fail('Ledger singleton uniqueness must be enforced by the database.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_release_rejects_an_inconsistent_reserved_balance_before_mutation(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(2);
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $system = $service->systemAccount();
        $reservation = InventoryReservation::factory()->create([
            'product_inventory_id' => $inventory->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 2, 'status' => 'active',
        ]);
        InventoryAllocationReservationLine::query()->create([
            'inventory_reservation_id' => $reservation->id, 'account_id' => $system->id,
            'quantity' => 2, 'status' => 'reserved',
        ]);

        $this->expectException(InventoryInvariantException::class);
        DB::transaction(fn () => $service->release($reservation, $owner, 'Invariant regression'));
    }

    public function test_damage_restore_and_transfer_preserve_exact_allocation_holders(): void
    {
        [$owner, $product, $warehouse, $inventory] = $this->foundation(6);
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $employee = $service->employeeAccount($owner->employee->id);
        $service->reconcile($inventory, $employee, 3, $owner, 'Employee allocation');

        DB::transaction(function () use ($service, $inventory, $owner): void {
            $locked = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $locked->decrement('available_quantity', 2);
            $service->markDamaged($locked, 2, $locked, $owner);
            $locked->increment('available_quantity', 2);
            $service->restoreDamaged($locked, 2, $locked, $owner);
        });
        $this->assertSame(3, InventoryAllocationBalance::query()->where('account_id', $employee->id)->where('product_inventory_id', $inventory->id)->value('allocated_quantity'));

        $destinationWarehouse = Warehouse::factory()->create();
        $destination = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $destinationWarehouse->id, 'available_quantity' => 0, 'reserved_quantity' => 0]);
        DB::transaction(function () use ($service, $inventory, $destination, $owner): void {
            $source = ProductInventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $source->decrement('available_quantity', 4);
            $service->dispatchTransfer($source, $source, 4, $owner);
            $destination->increment('available_quantity', 4);
            $service->completeTransfer($source, $destination->refresh(), $owner, false);
        });
        $this->assertSame(1, InventoryAllocationBalance::query()->where('account_id', $employee->id)->where('product_inventory_id', $destination->id)->value('allocated_quantity'));
        $this->assertSame(3, InventoryAllocationBalance::query()->where('account_id', $service->systemAccount()->id)->where('product_inventory_id', $destination->id)->value('allocated_quantity'));
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'damaged_allocation_restore']);
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'warehouse_transfer_receipt']);
    }

    public function test_employee_metrics_keep_other_and_system_allocations_distinct(): void
    {
        [$owner, , , $inventory] = $this->foundation(10);
        $other = Employee::factory()->create();
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $service->reconcile($inventory, $service->employeeAccount($owner->employee->id), 2, $owner, 'Owner allocation');
        $service->reconcile($inventory, $service->employeeAccount($other->id), 3, $owner, 'Other allocation');

        $metrics = $service->employeeMetrics($owner->employee->id, collect([$inventory->id]))->get($inventory->id);
        $this->assertSame(2, (int) $metrics->allocated);
        $this->assertSame(3, (int) $metrics->other_allocated);
        $this->assertSame(5, (int) $metrics->system_unallocated);
        $this->assertSame(10, (int) $metrics->ledger_allocated);
    }

    public function test_opening_stock_uses_system_in_shadow_and_requires_explicit_owner_in_strict(): void
    {
        [$owner, $product, $warehouse] = $this->foundation(0);
        app(InventoryService::class)->postOpeningStock(new PostOpeningStockData(
            $product->id, $warehouse->id, 2, 0, '10.0000', 'Shadow opening stock', (string) Str::uuid()
        ), $owner);
        $inventory = ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sole();
        $system = app(InventoryAllocationService::class)->systemAccount();
        $this->assertDatabaseHas('inventory_allocation_balances', [
            'account_id' => $system->id, 'product_inventory_id' => $inventory->id, 'allocated_quantity' => 2,
        ]);

        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
        $otherProduct = Product::factory()->create();
        try {
            app(InventoryService::class)->postOpeningStock(new PostOpeningStockData(
                $otherProduct->id, $warehouse->id, 1, 0, '10.0000', 'Strict opening stock', (string) Str::uuid()
            ), $owner);
            $this->fail('Strict opening stock must require an Employee or Team allocation account.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('product_inventories', ['product_id' => $otherProduct->id, 'available_quantity' => 1]);
        }
    }

    public function test_unknown_return_source_uses_system_in_shadow_but_remains_unallocated_in_strict(): void
    {
        [$owner, , , $shadowInventory] = $this->foundation(0);
        $service = app(InventoryAllocationService::class);
        DB::transaction(function () use ($service, $shadowInventory, $owner): void {
            $shadowInventory->increment('available_quantity');
            $service->restoreCustomerReturn($shadowInventory, $shadowInventory->refresh(), 1, $shadowInventory, $owner);
        });
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'customer_return_unknown_system', 'quantity' => 1]);

        InventoryAllocationSetting::query()->whereKey(1)->update(['enforcement_mode' => InventoryAllocationMode::Strict->value]);
        $strictInventory = ProductInventory::factory()->create([
            'product_id' => Product::factory(), 'warehouse_id' => $shadowInventory->warehouse_id,
            'available_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        DB::transaction(function () use ($service, $strictInventory, $owner): void {
            $strictInventory->increment('available_quantity');
            $service->restoreCustomerReturn($strictInventory, $strictInventory->refresh(), 1, $strictInventory, $owner);
        });
        $this->assertDatabaseHas('inventory_allocation_events', ['event_type' => 'customer_return_unallocated_strict', 'product_inventory_id' => $strictInventory->id]);
        $this->assertDatabaseMissing('inventory_allocation_balances', ['product_inventory_id' => $strictInventory->id, 'allocated_quantity' => 1]);
    }

    public function test_allocation_cannot_exceed_physical_inventory(): void
    {
        [$owner, , , $inventory] = $this->foundation(2);
        $service = app(InventoryAllocationService::class);
        $service->ensureShadowCoverage($inventory, $owner);
        $account = $service->employeeAccount($owner->employee->id);

        $this->expectException(ValidationException::class);
        $service->reconcile($inventory, $account, 3, $owner, 'Invalid overallocation attempt');
    }

    public function test_allocation_events_are_immutable(): void
    {
        [$owner, , , $inventory] = $this->foundation(2);
        app(InventoryAllocationService::class)->ensureShadowCoverage($inventory, $owner);
        $event = InventoryAllocationEvent::query()->firstOrFail();
        $this->expectException(ImmutableInventoryRecordException::class);
        $event->forceFill(['reason' => 'tampered'])->save();
    }

    public function test_allocation_management_page_uses_existing_inventory_permissions(): void
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $admin = User::factory()->create();
        Employee::factory()->for($admin)->role(EmployeeRole::Admin)->create(['email' => $admin->email]);

        $this->actingAs($owner);
        $this->assertTrue(InventoryAllocations::canAccess());
        Livewire::test(InventoryAllocations::class)
            ->assertOk()
            ->assertSee('Allocation Policy')
            ->assertSee('Immutable Allocation Events');
        $this->actingAs($admin);
        $this->assertTrue(InventoryAllocations::canAccess());
        $this->actingAs($staff);
        $this->assertFalse(InventoryAllocations::canAccess());
        Livewire::test(InventoryAllocations::class)->assertForbidden();
    }

    public function test_manager_with_explicit_view_permission_sees_only_responsibility_scoped_ledger_data(): void
    {
        [$owner, $visibleProduct, , $visibleInventory] = $this->foundation(2);
        $hiddenProduct = Product::factory()->create();
        $hiddenInventory = ProductInventory::factory()->create([
            'product_id' => $hiddenProduct->id, 'warehouse_id' => $visibleInventory->warehouse_id,
            'available_quantity' => 2, 'reserved_quantity' => 0,
        ]);
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id, 'permission_key' => InventoryPermission::ViewAllocations->value,
            'effect' => EmployeePermissionEffect::Allow, 'granted_by_user_id' => $owner->id, 'reason' => 'Scoped ledger test',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $assignment = ResponsibilityAssignment::factory()->create([
            'employee_id' => $manager->employee->id, 'assigned_by_user_id' => $owner->id,
        ]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $visibleProduct->id]);
        app(InventoryAllocationService::class)->ensureShadowCoverage($visibleInventory, $owner);
        app(InventoryAllocationService::class)->ensureShadowCoverage($hiddenInventory, $owner);

        $this->actingAs($manager);
        Livewire::test(InventoryAllocations::class)
            ->assertOk()
            ->assertSee($visibleProduct->sku)
            ->assertDontSee($hiddenProduct->sku)
            ->assertDontSee('Allocation Policy')
            ->assertDontSee('Reconcile Legacy Stock')
            ->assertDontSee('Automatic Allocation Rules');
    }

    private function foundation(int $quantity): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => $quantity, 'reserved_quantity' => 0, 'average_cost' => '10.0000']);

        return [$owner->refresh(), $product, $warehouse, $inventory];
    }

    private function orderData(User $owner, Product $product, Warehouse $warehouse, int $quantity): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData($warehouse->id, null, null, now()->toDateString(), $owner->employee->id,
            'Allocation test', [new OrderItemData($product->id, $quantity, '100.00')], (string) Str::uuid());
    }

    private function approvedPurchase(int $quantity, string $cost, ?User $owner = null): array
    {
        if ($owner === null) {
            $owner = User::factory()->create();
            Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        }
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(Supplier::factory()->create()->id,
            Warehouse::factory()->create()->id, now()->toDateString(),
            [new PurchaseItemData(Product::factory()->create()->id, $quantity, $cost)], 'INV-'.Str::random(8), now()->toDateString()), $owner);
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Owner approval.', true), $owner);

        return [$owner->refresh(), $purchase->load('items')];
    }
}
