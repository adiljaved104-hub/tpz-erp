<?php

namespace Tests\Feature\Orders;

use App\Actions\Purchases\QuickStockPurchase;
use App\Contracts\EmployeePermissionOverrideResolver;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\QuickStockPurchaseData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\PurchasePermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\SupplierDirectShippingAuthorizationException;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Resources\WebSalesOrders\Pages\ViewWebSalesOrder;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\PurchaseReceipt;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Orders\ManualSourcingService;
use App\Services\Orders\WebSalesService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SupplierDirectShippingPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_staff_ship_and_replay_without_quick_receive_or_invoice_changes(): void
    {
        [$owner, $staff, $order] = $this->scenario();
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Test Company', 'trn' => '100000000000001', 'address_en' => 'Dubai']);
        $invoice = app(TaxInvoiceService::class)->create([
            'customer_name' => 'Test Customer', 'customer_address' => 'Dubai',
            'source_order_id' => $order->id, 'order_reference' => $order->reference,
            'invoice_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Original issued description', 'quantity' => 1, 'unit_price_including_vat' => '1050.00']],
        ], $owner);
        $snapshot = $invoice->fresh()->toArray();
        $invoiceItems = $invoice->items()->get()->toArray();
        $this->assertFalse(app(PurchaseAuthorization::class)->allows($staff, PurchasePermission::QuickReceive));
        $this->actingAs($staff);
        $key = (string) Str::uuid();
        $shipped = app(WebSalesService::class)->ship($order, $key, $staff);
        app(WebSalesService::class)->ship($shipped, $key, $staff);
        try {
            app(WebSalesService::class)->ship($shipped, (string) Str::uuid(), $staff);
            $this->fail('A different confirmation must not ship an already shipped Order again.');
        } catch (InvalidOrderTransitionException) {
            $this->assertSame(OrderStatus::Fulfilled, $order->fresh()->status);
        }
        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 1);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertSame(0, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame(0, ProductInventory::query()->sole()->reserved_quantity);
        $this->assertSame($snapshot, $invoice->fresh()->toArray());
        $this->assertSame($invoiceItems, $invoice->items()->get()->toArray());
        $this->assertFalse(app(PurchaseAuthorization::class)->allows($staff, PurchasePermission::QuickReceive));
    }

    public function test_staff_default_deny_and_action_warning_are_clear_and_create_nothing(): void
    {
        [, $staff, $order] = $this->scenario(grant: false);
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewWebSalesOrder::class, ['record' => $order->id])
            ->callAction('ship')->assertNotified(
                Notification::make()->warning()->title('Cannot ship Web Sale')
                    ->body((new SupplierDirectShippingAuthorizationException)->getMessage())
            );
        $this->assertNoShipment($order);
    }

    public function test_general_quick_receive_does_not_substitute_for_dedicated_shipping_permission(): void
    {
        [$owner, $staff, $order] = $this->scenario(grant: false);
        $this->override($owner, $staff, PurchasePermission::QuickReceive->value, EmployeePermissionEffect::Allow);
        $this->expectException(SupplierDirectShippingAuthorizationException::class);
        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $staff);
        } finally {
            $this->assertNoShipment($order);
        }
    }

    public function test_dedicated_permission_cannot_access_an_unrelated_employees_order(): void
    {
        [$owner, $staff, $order] = $this->scenario();
        $order->forceFill(['created_by_user_id' => $owner->id, 'handled_by_employee_id' => $owner->employee->id])->save();
        $this->expectException(AuthorizationException::class);
        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $staff);
        } finally {
            $this->assertNoShipment($order);
        }
    }

    public function test_direct_permission_does_not_authorize_standalone_quick_purchase(): void
    {
        [, $staff, $order] = $this->scenario();
        try {
            app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
                $order->warehouse_id, today()->toDateString(),
                [new PurchaseItemData($order->items()->sole()->product_id, 1, '100.00')],
                (string) Str::uuid(),
            ), $staff);
            $this->fail('Supplier-direct permission must not grant standalone purchasing.');
        } catch (AuthorizationException) {
            $this->assertFalse(app(PurchaseAuthorization::class)->allows($staff, PurchasePermission::Receive));
        }
        $this->assertNoShipment($order);
    }

    #[DataProvider('requiredPermissions')]
    public function test_other_required_permissions_cannot_be_bypassed(string $key): void
    {
        [$owner, $staff, $order] = $this->scenario();
        $this->override($owner, $staff, $key, EmployeePermissionEffect::Deny);
        $this->expectException(AuthorizationException::class);
        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $staff);
        } finally {
            $this->assertNoShipment($order);
        }
    }

    public static function requiredPermissions(): array
    {
        return array_map(fn ($key) => [$key], ['web_sales.view', 'web_sales.update', 'order.fulfill', 'order.reserve', 'web_sales.supplier_direct_ship']);
    }

    public function test_responsibility_revocation_blocks_an_otherwise_permitted_employee(): void
    {
        [, $staff, $order] = $this->scenario();
        ResponsibilityAssignment::query()->where('employee_id', $staff->employee->id)->update(['status' => 'inactive', 'active_fingerprint' => null, 'ended_at' => now()]);
        $this->expectException(AuthorizationException::class);
        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $staff);
        } finally {
            $this->assertNoShipment($order);
        }
    }

    #[DataProvider('invalidSourcing')]
    public function test_invalid_sourcing_rolls_back_without_posting(array $changes): void
    {
        [, $staff, $order] = $this->scenario();
        $order->manualSourcingLines()->sole()->forceFill($changes)->save();
        $this->expectException(ValidationException::class);
        try {
            app(WebSalesService::class)->ship($order, (string) Str::uuid(), $staff);
        } finally {
            $this->assertNoShipment($order);
        }
    }

    public static function invalidSourcing(): array
    {
        return [[['actual_unit_cost' => null]], [['actual_unit_cost' => 0]], [['purchase_currency' => 'USD']], [['purchased_on' => null]], [['status' => 'cancelled']]];
    }

    public function test_registered_permission_preserves_owner_admin_defaults_and_employee_explicit_deny(): void
    {
        [$owner, $staff, $order] = $this->scenario();
        $admin = Employee::factory()->role(EmployeeRole::Admin)->create()->user;
        $auth = app(WebSalesAuthorization::class);
        $this->assertTrue($auth->allows($owner, WebSalesPermission::SupplierDirectShip, $order));
        $this->assertTrue($auth->allows($admin, WebSalesPermission::SupplierDirectShip, $order));
        $this->override($owner, $admin, WebSalesPermission::SupplierDirectShip->value, EmployeePermissionEffect::Deny);
        $this->assertFalse($auth->allows($admin, WebSalesPermission::SupplierDirectShip, $order));
        $this->override($owner, $owner, WebSalesPermission::SupplierDirectShip->value, EmployeePermissionEffect::Deny);
        $this->assertTrue($auth->allows($owner, WebSalesPermission::SupplierDirectShip, $order));
        $this->assertSame('Supplier-Direct Shipping', app(EmployeePermissionCatalog::class)->find(WebSalesPermission::SupplierDirectShip->value)['label']);
        $this->assertContains(WebSalesPermission::SupplierDirectShip->value, app(AccessControlModuleRegistry::class)->keyed()['web_sales']['advanced_keys']);
        $this->assertSame(['missing' => [], 'unknown' => [], 'duplicates' => []], app(AccessControlModuleRegistry::class)->reconcile());
    }

    public function test_owner_and_admin_can_assign_and_revoke_only_the_dedicated_permission_in_access_control(): void
    {
        [$owner, $staff, $order] = $this->scenario(grant: false);
        $admin = Employee::factory()->role(EmployeeRole::Admin)->create()->user;
        foreach ([$owner, $admin] as $editor) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Livewire::actingAs($editor)->test(AccessControl::class)
                ->call('selectEmployee', $staff->employee->id)
                ->call('stagePermission', WebSalesPermission::SupplierDirectShip->value, 'allow')
                ->call('saveChanges')->assertHasNoErrors();
            $this->assertTrue(app(WebSalesAuthorization::class)->allows($staff, WebSalesPermission::SupplierDirectShip, $order));
            $this->assertFalse(app(PurchaseAuthorization::class)->allows($staff, PurchasePermission::QuickReceive));
            Livewire::test(AccessControl::class)
                ->call('selectEmployee', $staff->employee->id)
                ->assertSet('draftSettings', fn (array $settings): bool => $settings[WebSalesPermission::SupplierDirectShip->value] === 'allow')
                ->call('stagePermission', WebSalesPermission::SupplierDirectShip->value, 'deny')
                ->call('saveChanges')->assertHasNoErrors();
            $this->assertFalse(app(WebSalesAuthorization::class)->allows($staff, WebSalesPermission::SupplierDirectShip, $order));
        }
        $this->assertDatabaseMissing('employee_permission_overrides', ['employee_id' => $staff->employee->id, 'permission_key' => PurchasePermission::QuickReceive->value]);
    }

    public function test_warehouse_receipt_sourcing_uses_existing_receipt_without_direct_shipping_permission(): void
    {
        [$owner, $staff, $order] = $this->scenario(mode: 'warehouse_receipt');
        $line = $order->manualSourcingLines()->sole();
        // Existing receiving path and responsibility/allocation rules remain intact.
        $result = app(QuickStockPurchase::class)->handle(new QuickStockPurchaseData(
            $order->warehouse_id, today()->toDateString(),
            [new PurchaseItemData($line->product_id, 1, '100.00')], (string) Str::uuid(),
        ), $owner);
        app(ManualSourcingService::class)->confirmReceipt($line, $result->receipt, $owner);
        $this->override($owner, $staff, WebSalesPermission::SupplierDirectShip->value, EmployeePermissionEffect::Deny);
        app(WebSalesService::class)->ship($order->fresh(), (string) Str::uuid(), $staff);
        $this->assertSame(OrderStatus::Fulfilled, $order->fresh()->status);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertNotSame('direct_to_customer', PurchaseReceipt::query()->sole()->custody_mode);
        $this->assertDatabaseCount('manual_sourcing_shipment_postings', 0);
    }

    private function scenario(bool $grant = true, string $mode = 'supplier_direct'): array
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;
        $product = Product::factory()->create();
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $staff->employee->id, 'assigned_by_user_id' => $owner->id, 'assign_stock_by_default' => true]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $product->id]);
        $order = app(WebSalesService::class)->createConfirmed(new WebSalesOrderData(
            'Test Customer', '+971500000000', WebSalesChannel::WhatsApp, WebSalesDeliveryType::Courier,
            'Test courier', null,
            [new OrderItemData($product->id, 1, '1050.00', sourceType: 'manual_sourced', sourceMode: $mode, actualUnitCost: '100.00', purchaseCurrency: 'AED', purchasedOn: today()->toDateString())],
            (string) Str::uuid(), customerAddress: 'Dubai',
        ), $staff);
        $this->override($owner, $staff, OrderPermission::Fulfill->value, EmployeePermissionEffect::Allow);
        if ($grant) {
            $this->override($owner, $staff, WebSalesPermission::SupplierDirectShip->value, EmployeePermissionEffect::Allow);
        }

        return [$owner, $staff, $order];
    }

    private function override(User $owner, User $user, string $key, EmployeePermissionEffect $effect): void
    {
        EmployeePermissionOverride::query()->updateOrCreate(['employee_id' => $user->employee->id, 'permission_key' => $key], [
            'effect' => $effect, 'granted_by_user_id' => $owner->id, 'reason' => 'Supplier-direct shipping regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
    }

    private function assertNoShipment(Order $order): void
    {
        $this->assertSame(OrderStatus::PendingReview, $order->fresh()->status);
        foreach (['purchases', 'purchase_receipts', 'stock_movements', 'order_fulfillments', 'manual_sourcing_shipment_postings', 'accounting_obligations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
