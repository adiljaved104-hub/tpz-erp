<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Exceptions\InsufficientInventoryException;
use App\Filament\Pages\Administration\OrderSettings as OrderSettingsPage;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\OrderAmendmentLine;
use App\Models\OrderSetting;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\StockMovement;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderAmendmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAmendmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_inside_window_increase_decrease_price_reference_and_retry_are_atomic_and_audited(): void
    {
        [$actor, $order, $stock] = $this->reservedOrder();
        $service = app(OrderAmendmentService::class);
        $item = $order->items()->sole();
        $key = (string) Str::uuid();
        $input = ['reason' => 'Customer changed order quantities', 'idempotency_key' => $key,
            'external_order_number' => 'EXT-101', 'items' => [['id' => $item->id, 'quantity' => 4, 'selling_price' => '120.00']]];

        $amendment = $service->amend($order, $input, $actor);
        $this->assertSame($amendment->id, $service->amend($order, $input, $actor)->id);
        $this->assertSame(4, $item->refresh()->ordered_quantity);
        $this->assertSame('120.00', $item->selling_price);
        $this->assertSame(4, $item->reservation->quantity);
        $this->assertSame(4, $stock->refresh()->reserved_quantity);
        $this->assertSame('EXT-101', $order->refresh()->external_order_number);
        $this->assertSame(1, OrderAmendment::query()->count());
        $this->assertSame(3, $amendment->lines()->count());
        $this->assertSame(2, StockMovement::query()->count());

        $service->amend($order, ['reason' => 'Customer reduced the requested stock', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['id' => $item->id, 'quantity' => 1]]], $actor);
        $this->assertSame(1, $item->fresh()->reservation->quantity);
        $this->assertSame(1, $stock->refresh()->reserved_quantity);
        $this->assertSame(-3, StockMovement::query()->latest('id')->firstOrFail()->reserved_delta);
        $this->assertSame('120.00', $item->fresh()->selling_price);
    }

    public function test_insufficient_stock_rolls_back_order_reservation_and_ledger(): void
    {
        [$actor, $order, $stock] = $this->reservedOrder(2);
        $item = $order->items()->sole();
        try {
            app(OrderAmendmentService::class)->amend($order, ['reason' => 'Need impossible additional stock',
                'idempotency_key' => (string) Str::uuid(), 'items' => [['id' => $item->id, 'quantity' => 9]]], $actor);
            $this->fail('Insufficient stock must reject amendment.');
        } catch (InsufficientInventoryException) {
            $this->assertSame(2, $item->fresh()->ordered_quantity);
            $this->assertSame(2, $stock->refresh()->reserved_quantity);
            $this->assertDatabaseCount('order_amendments', 0);
            $this->assertDatabaseCount('stock_movements', 1);
        }
    }

    public function test_expired_window_requires_explicit_override_and_settings_are_validated(): void
    {
        [$owner, $order] = $this->reservedOrder();
        $order->forceFill(['reserved_at' => now()->subHours(2)])->save();
        $service = app(OrderAmendmentService::class);
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);

        $this->assertFalse($service->canAmend($order->refresh(), $manager));
        try {
            $service->amend($order, ['reason' => 'Past normal amendment window', 'idempotency_key' => (string) Str::uuid(),
                'external_order_number' => 'LATE-1'], $manager);
            $this->fail('Manager cannot override expiry by default.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('order_amendments', 0);
        }

        $amendment = $service->amend($order, ['reason' => 'Owner approves late correction', 'idempotency_key' => (string) Str::uuid(),
            'external_order_number' => 'LATE-1'], $owner);
        $this->assertTrue($amendment->after_window_override);
        $admin = User::factory()->create();
        Employee::factory()->for($admin)->role(EmployeeRole::Admin)->create(['email' => $admin->email]);
        $this->assertTrue($service->amend($order, ['reason' => 'Admin approves another late correction',
            'idempotency_key' => (string) Str::uuid(), 'external_order_number' => 'LATE-2'], $admin)->after_window_override);
        $service->saveWindowHours(5, $owner);
        $this->assertSame(5, OrderSetting::query()->findOrFail(1)->amendment_window_hours);
        $this->expectException(ValidationException::class);
        $service->saveWindowHours(3, $owner);
    }

    public function test_existing_invoice_blocks_financial_changes_but_not_external_reference(): void
    {
        [$actor, $order] = $this->reservedOrder();
        $item = $order->items()->sole();
        $itemUpdatedAt = $item->updated_at;
        TaxInvoice::query()->create([
            'source_order_id' => $order->id, 'invoice_number' => 'TEST-1', 'status' => 'issued',
            'invoice_date' => now()->toDateString(), 'customer_name' => 'Test customer', 'vat_rate' => '5.00',
            'subtotal_excluding_vat' => '190.48', 'vat_amount' => '9.52', 'grand_total' => '200.00',
            'seller_snapshot' => [], 'terms_en_snapshot' => 'Terms', 'created_by_user_id' => $actor->id,
            'issued_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
        $service = app(OrderAmendmentService::class);
        foreach ([['quantity' => 3], ['quantity' => 2, 'selling_price' => '101.00']] as $change) {
            try {
                $service->amend($order, ['reason' => 'Invoice prevents this modification', 'idempotency_key' => (string) Str::uuid(),
                    'items' => [array_merge(['id' => $item->id], $change)]], $actor);
                $this->fail('Issued invoice prevents quantity or price change.');
            } catch (ValidationException) {
                $this->assertSame(2, $item->fresh()->ordered_quantity);
            }
        }
        $service->amend($order, ['reason' => 'External reference correction', 'idempotency_key' => (string) Str::uuid(),
            'external_order_number' => 'NEW-REF'], $actor);
        $this->assertSame('NEW-REF', $order->refresh()->external_order_number);
        $this->assertTrue($itemUpdatedAt->equalTo($item->fresh()->updated_at));
        $this->assertDatabaseCount('order_amendments', 1);
    }

    public function test_fulfilled_order_cannot_be_amended(): void
    {
        [$actor, $order, $stock] = $this->reservedOrder();
        $stock->forceFill(['average_cost' => '50.0000'])->save();
        app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $actor);
        $this->expectException(ValidationException::class);
        app(OrderAmendmentService::class)->amend($order, ['reason' => 'Attempt to change shipped order',
            'idempotency_key' => (string) Str::uuid(), 'external_order_number' => 'FORBIDDEN'], $actor);
    }

    public function test_amendment_history_is_immutable_and_reused_key_cannot_change_request(): void
    {
        [$actor, $order] = $this->reservedOrder();
        $service = app(OrderAmendmentService::class);
        $key = (string) Str::uuid();
        $history = $service->amend($order, ['reason' => 'Correct marketplace reference', 'idempotency_key' => $key,
            'external_order_number' => 'MKT-1'], $actor);
        $this->expectException(\LogicException::class);
        $history->forceFill(['reason' => 'Changed afterwards'])->save();
    }

    public function test_amendment_line_cannot_be_changed_and_conflicting_retry_is_rejected(): void
    {
        [$actor, $order] = $this->reservedOrder();
        $service = app(OrderAmendmentService::class);
        $key = (string) Str::uuid();
        $history = $service->amend($order, ['reason' => 'Correct marketplace reference', 'idempotency_key' => $key,
            'external_order_number' => 'MKT-1'], $actor);
        try {
            $service->amend($order, ['reason' => 'Correct marketplace reference', 'idempotency_key' => $key,
                'external_order_number' => 'MKT-2'], $actor);
            $this->fail('A reused key must not alter an earlier amendment.');
        } catch (ValidationException) {
            $this->assertSame('MKT-1', $order->refresh()->external_order_number);
            $this->assertDatabaseCount('order_amendments', 1);
        }
        $this->expectException(\LogicException::class);
        OrderAmendmentLine::query()->findOrFail($history->lines()->sole()->id)->delete();
    }

    public function test_settings_and_override_are_owner_admin_only_by_default(): void
    {
        [$actor, $order] = $this->reservedOrder();
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        $this->assertTrue(app(OrderAuthorization::class)->allows($actor, OrderPermission::ManageAmendmentSettings));
        $this->assertFalse(app(OrderAuthorization::class)->allows($manager, OrderPermission::ManageAmendmentSettings));
        $this->assertFalse(app(OrderAuthorization::class)->allows($manager, OrderPermission::AmendAfterWindow, $order));
        $this->actingAs($manager);
        $this->assertFalse(OrderSettingsPage::canAccess());
        $this->expectException(AuthorizationException::class);
        app(OrderAmendmentService::class)->saveWindowHours(24, $manager);
    }

    public function test_order_view_offers_controlled_action_only_to_authorized_open_order(): void
    {
        [$actor, $order] = $this->reservedOrder();
        Livewire::actingAs($actor)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('amendOrder')
            ->assertActionHidden('amendmentHistory')
            ->callAction('amendOrder', [
                'external_order_number' => 'UI-UPDATED',
                'reason' => 'Customer amended their reference', 'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertHasNoActionErrors();
        $this->assertTrue(app(OrderAmendmentService::class)->canAmend($order, $actor));
        $this->assertSame('UI-UPDATED', $order->refresh()->external_order_number);
        $this->assertDatabaseCount('order_amendments', 1);
    }

    /** @return array{User, Order, ProductInventory} */
    private function reservedOrder(int $available = 10): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $stock = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => $available, 'reserved_quantity' => 0]);
        $order = app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, null, null, now()->toDateString(), $owner->employee->id, 'Amendment test',
            [new OrderItemData($product->id, 2, '100.00')], (string) Str::uuid(),
        ), $owner);

        return [$owner->refresh(), $order, $stock];
    }
}
