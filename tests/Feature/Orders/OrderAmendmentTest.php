<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Exceptions\InsufficientInventoryException;
use App\Filament\Pages\Administration\OrderSettings as OrderSettingsPage;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
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
use Illuminate\Support\Facades\DB;
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

    public function test_directly_shipped_platform_order_date_and_external_reference_corrections_use_immutable_ledger(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        $service = app(OrderAmendmentService::class);
        $this->assertTrue($service->canCorrectShipped($order, $owner));
        $this->assertNotNull($service->expiresAt($order));
        $oldDate = $order->order_date->format('Y-m-d');
        $newDate = now()->subDay()->toDateString();
        $key = (string) Str::uuid();
        $input = ['reason' => 'Correct original sale date and platform reference', 'idempotency_key' => $key,
            'order_date' => $newDate, 'external_order_number' => 'PLATFORM-CORRECTED'];
        $amendment = $service->correctShipped($order, $input, $owner);
        $this->assertSame($amendment->id, $service->correctShipped($order, $input, $owner)->id);
        $this->assertSame($newDate, $order->refresh()->order_date->format('Y-m-d'));
        $this->assertSame('PLATFORM-CORRECTED', $order->external_order_number);
        $this->assertDatabaseHas('order_amendment_lines', ['field' => 'order_date', 'old_value' => $oldDate, 'new_value' => $newDate]);
        $this->assertDatabaseHas('order_amendment_lines', ['field' => 'external_order_number', 'new_value' => 'PLATFORM-CORRECTED']);
        $this->assertFalse($amendment->after_window_override);
    }

    public function test_shipped_price_correction_changes_order_totals_without_rewriting_fulfillment_cogs_or_invoice(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        $item = $order->items()->sole();
        $movementBefore = StockMovement::query()->get()->toArray();
        $fulfillmentBefore = $order->fulfillment->items()->sole()->toArray();
        $invoice = $this->linkedInvoice($order, $owner);
        $invoiceBefore = DB::table('tax_invoices')->where('id', $invoice->id)->first();

        app(OrderAmendmentService::class)->correctShipped($order, [
            'reason' => 'Approved post-sale price correction', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['id' => $item->id, 'selling_price' => '125.00']],
        ], $owner);

        $this->assertSame('125.00', $item->fresh()->selling_price);
        $this->assertSame('250.00', $order->refresh()->grand_total);
        $this->assertSame(2, $item->fresh()->ordered_quantity);
        $this->assertSame($fulfillmentBefore, $order->fulfillment->items()->sole()->toArray());
        $this->assertSame($movementBefore, StockMovement::query()->get()->toArray());
        $this->assertEquals($invoiceBefore, DB::table('tax_invoices')->where('id', $invoice->id)->first());
    }

    public function test_shipped_warehouse_correction_is_documentary_and_original_stock_history_stays_intact(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        $historicalId = $order->warehouse_id;
        $target = Warehouse::factory()->create(['status' => true]);
        $movements = StockMovement::query()->get()->toArray();
        $fulfillment = $order->fulfillment->items()->sole()->toArray();
        $service = app(OrderAmendmentService::class);
        $amendment = $service->correctShipped($order, [
            'reason' => 'Correct recorded dispatch warehouse', 'idempotency_key' => (string) Str::uuid(),
            'warehouse_id' => $target->id,
        ], $owner);

        $this->assertSame($historicalId, $order->refresh()->warehouse_id);
        $this->assertSame($target->id, $service->correctedWarehouseId($order));
        $this->assertSame($target->name, $service->correctedWarehouseName($order));
        $this->assertSame($movements, StockMovement::query()->get()->toArray());
        $this->assertSame($fulfillment, $order->fulfillment->items()->sole()->toArray());
        $line = $amendment->lines()->where('field', 'warehouse_correction')->sole();
        $this->assertSame($historicalId, json_decode($line->old_value, true)['warehouse_id']);
        $this->assertSame($target->id, json_decode($line->new_value, true)['warehouse_id']);
        Livewire::actingAs($owner)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Shipped From (historical)')->assertSee('Corrected Warehouse (documentary only)')
            ->assertSee($target->name);
    }

    public function test_shipped_quantity_product_and_configuration_payloads_are_rejected_without_side_effects(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        $item = $order->items()->sole();
        foreach ([
            ['items' => [['id' => $item->id, 'quantity' => 3, 'selling_price' => '100.00']]],
            ['items' => [['id' => $item->id, 'product_id' => 999, 'selling_price' => '100.00']]],
            ['items' => [['id' => $item->id, 'upgrade_recipe_id' => 999, 'selling_price' => '100.00']]],
            ['quantity' => 3],
        ] as $attempt) {
            try {
                app(OrderAmendmentService::class)->correctShipped($order, array_merge($attempt, [
                    'reason' => 'Attempt unsupported shipped change', 'idempotency_key' => (string) Str::uuid(),
                ]), $owner);
                $this->fail('Shipped products and quantities cannot be amended.');
            } catch (ValidationException) {
                $this->assertSame(2, $item->fresh()->ordered_quantity);
            }
        }
        $this->assertDatabaseCount('order_amendments', 0);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_post_shipment_requires_reason_and_window_override_is_owner_admin_only_by_default(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        $service = app(OrderAmendmentService::class);
        try {
            $service->correctShipped($order, ['reason' => '', 'idempotency_key' => (string) Str::uuid(),
                'external_order_number' => 'WITHOUT-REASON'], $owner);
            $this->fail('Reason is required.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('order_amendments', 0);
        }
        DB::table('order_status_events')->where('order_id', $order->id)->where('from_status', 'draft')
            ->update(['created_at' => now()->subHours(2)]);
        $admin = User::factory()->create();
        Employee::factory()->for($admin)->role(EmployeeRole::Admin)->create(['email' => $admin->email]);
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        $this->assertFalse($service->canCorrectShipped($order, $manager));
        $this->assertTrue($service->correctShipped($order, ['reason' => 'Owner approves late correction',
            'idempotency_key' => (string) Str::uuid(), 'external_order_number' => 'OWNER-LATE'], $owner)->after_window_override);
        $this->assertTrue($service->correctShipped($order, ['reason' => 'Admin approves late correction',
            'idempotency_key' => (string) Str::uuid(), 'external_order_number' => 'ADMIN-LATE'], $admin)->after_window_override);
    }

    public function test_post_shipment_action_has_distinct_label_and_pre_shipment_action_is_hidden(): void
    {
        [$owner, $order] = $this->shippedPlatformOrder();
        Livewire::actingAs($owner)->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('postShipmentCorrection')
            ->assertActionHidden('amendOrder');
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

    /** @return array{User, Order} */
    private function shippedPlatformOrder(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $platform = MarketplacePlatform::factory()->create(['status' => true]);
        ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => '50.0000']);
        $order = app(SaveAsShippedOrder::class)->handle(new SaveAndReserveOrderData(
            $warehouse->id, $platform->id, 'PLATFORM-ORIGINAL', now()->toDateString(),
            $owner->employee->id, 'Shipped amendment test', [new OrderItemData($product->id, 2, '100.00')],
            (string) Str::uuid(),
        ), $owner);

        return [$owner->refresh(), $order];
    }

    private function linkedInvoice(Order $order, User $actor): TaxInvoice
    {
        return TaxInvoice::query()->create([
            'source_order_id' => $order->id, 'invoice_number' => 'SHIP-TEST-1', 'status' => 'issued',
            'invoice_date' => now()->toDateString(), 'customer_name' => 'Original customer',
            'vat_rate' => '5.00', 'subtotal_excluding_vat' => '190.48', 'vat_amount' => '9.52',
            'grand_total' => '200.00', 'seller_snapshot' => [], 'terms_en_snapshot' => 'Unchanged terms',
            'created_by_user_id' => $actor->id, 'issued_at' => now(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
