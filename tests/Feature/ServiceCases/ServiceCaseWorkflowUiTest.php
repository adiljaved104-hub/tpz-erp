<?php

namespace Tests\Feature\ServiceCases;

use App\Enums\ComplaintCategory;
use App\Enums\EmployeeRole;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\ComplaintException;
use App\Filament\Resources\Complaints\Pages\CreateComplaint;
use App\Filament\Resources\WarrantyRepairs\Pages\CreateWarrantyRepair;
use App\Filament\Resources\WarrantyRepairs\Pages\EditWarrantyRepair;
use App\Filament\Resources\WarrantyRepairs\Pages\ViewWarrantyRepair;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Models\WarrantyRepairStatusEvent;
use App\Services\ReferenceSequenceService;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\ServiceCaseOrderContextService;
use App\Services\ServiceCases\WarrantySlaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceCaseWorkflowUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_complaint_create_button_accepts_hydrated_enum_creates_event_and_is_inventory_neutral(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'reserved_quantity' => 1, 'damaged_quantity' => 2, 'average_cost' => '100.0000']);
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $ambient = DB::transactionLevel();
        $probe = new class extends ReferenceSequenceService
        {
            public int $level = -1;

            public function nextComplaintReference(?int $year = null): string
            {
                $this->level = DB::transactionLevel();

                return 'CMP-2026-990099';
            }
        };
        $this->app->instance(ReferenceSequenceService::class, $probe);
        $this->actingAs($owner);

        Livewire::test(CreateComplaint::class)
            ->fillForm(['product_id' => $product->id, 'category' => ComplaintCategory::ChargerMissing, 'quantity' => 1, 'description' => 'Charger missing from parcel'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $complaint = Complaint::query()->sole();
        $this->assertSame('CMP-2026-990099', $complaint->reference);
        $this->assertSame($ambient, $probe->level);
        $this->assertDatabaseHas('complaint_status_events', ['complaint_id' => $complaint->id, 'to_status' => 'open']);
        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_complaint_form_requires_product_and_surfaces_validation(): void
    {
        [$owner] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateComplaint::class)
            ->fillForm(['category' => ComplaintCategory::Other, 'description' => 'Needs review'])
            ->call('create')
            ->assertHasFormErrors(['product_id' => 'required']);

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_complaint_service_exception_is_reported_as_a_filament_notification(): void
    {
        [$owner, $product] = $this->foundation();
        $this->actingAs($owner);
        $this->mock(ComplaintService::class)->shouldReceive('create')->once()->andThrow(new ComplaintException('Complaint creation was rejected.'));

        Livewire::test(CreateComplaint::class)
            ->fillForm(['product_id' => $product->id, 'category' => ComplaintCategory::Other, 'description' => 'Needs review'])
            ->call('create')
            ->assertNotified('Complaint could not be created');

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_order_context_searches_internal_and_external_ids_and_limits_products(): void
    {
        [$owner, $product, $warehouse, $platform] = $this->foundation();
        $second = Product::factory()->create();
        $unrelated = Product::factory()->create();
        $order = $this->order($owner, $warehouse, $platform, [$product, $second]);
        CustomerReturn::query()->create(['reference' => 'RTN-2026-990001', 'order_id' => $order->id, 'marketplace_platform_id' => $platform->id, 'fulfillment_warehouse_id' => $warehouse->id, 'status' => 'draft', 'return_source' => 'manual', 'receiving_warehouse_id' => $warehouse->id, 'reported_at' => now(), 'created_by_user_id' => $owner->id, 'idempotency_key' => (string) Str::uuid()]);
        $context = app(ServiceCaseOrderContextService::class);

        $this->assertArrayHasKey($order->id, $context->searchOrders('SO-2026-990001', $owner));
        $this->assertArrayHasKey($order->id, $context->searchOrders('AMZ-ORDER-77', $owner));
        $this->assertEqualsCanonicalizing([$product->id, $second->id], array_keys($context->orderProducts($order->id, $owner)));
        $this->assertSame($platform->id, $context->context($order->id, $owner)['platform_id']);
        $this->assertSame(CustomerReturn::query()->sole()->id, $context->context($order->id, $owner)['customer_return_id']);

        $this->expectException(ValidationException::class);
        $context->assertProductBelongsToOrder($order->id, $unrelated->id, $owner);
    }

    public function test_one_item_order_reactively_populates_complaint_and_warranty_forms(): void
    {
        [$owner, $product, $warehouse, $platform] = $this->foundation();
        $order = $this->order($owner, $warehouse, $platform, [$product]);
        $this->actingAs($owner);

        Livewire::test(CreateComplaint::class)
            ->set('data.order_id', $order->id)
            ->assertFormSet(['order_id' => $order->id, 'marketplace_platform_id' => $platform->id, 'product_id' => $product->id, 'quantity' => 2]);

        Livewire::test(CreateWarrantyRepair::class)
            ->set('data.order_id', $order->id)
            ->assertFormSet(['order_id' => $order->id, 'marketplace_platform_id' => $platform->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2]);
    }

    public function test_manual_warranty_fallback_remains_inventory_neutral(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateWarrantyRepair::class)
            ->fillForm(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'issue_description' => 'Manual service case', 'received_at' => now()])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseCount('warranty_repairs', 1);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_receive_form_reactively_displays_fourteen_day_sla_due(): void
    {
        [$owner] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateWarrantyRepair::class)
            ->set('data.received_at', '2026-08-20 09:45:24')
            ->assertSee('03-Sep-2026 09:45:24 AM');
    }

    public function test_warranty_view_renders_case_details_timeline_sla_actions_and_null_fields_safely(): void
    {
        [$owner, $product, $warehouse, $platform] = $this->foundation();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'reserved_quantity' => 1, 'damaged_quantity' => 2, 'average_cost' => '100.0000']);
        $case = $this->warranty($owner, $product, $warehouse, '2026-08-20 09:45:24');
        $case->forceFill(['marketplace_platform_id' => $platform->id, 'expected_return_at' => '2026-08-28 10:00:00'])->save();
        WarrantyRepairStatusEvent::query()->create(['warranty_repair_id' => $case->id, 'from_status' => null, 'to_status' => WarrantyRepairStatus::Received, 'note' => 'Service item received', 'changed_by_user_id' => $owner->id, 'changed_at' => '2026-08-20 09:45:24']);
        $before = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $this->actingAs($owner);

        Livewire::test(ViewWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->assertOk()
            ->assertSee('Main Case Details')
            ->assertSee($case->reference)
            ->assertSee($product->name)
            ->assertSee($product->sku)
            ->assertSee('03 Sep 2026, 09:45:24 AM')
            ->assertSee('28 Aug 2026, 10:00 AM')
            ->assertSee('Not moved')
            ->assertSee('Status Timeline')
            ->assertSee('Service item received')
            ->assertSee('Edit')
            ->assertSee('Inspect');

        $this->assertSame($before, $inventory->refresh()->only(array_keys($before)));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_future_received_at_is_rejected_with_friendly_form_validation(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateWarrantyRepair::class)
            ->fillForm(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'issue_description' => 'Future date test', 'received_at' => now()->addDay()])
            ->call('create')
            ->assertHasFormErrors(['received_at']);

        $this->assertDatabaseCount('warranty_repairs', 0);
    }

    public function test_staff_without_warranty_scope_cannot_view_direct_url(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $case = $this->warranty($owner, $product, $warehouse, now()->subDay()->toDateTimeString());
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->actingAs($staff);

        Livewire::test(ViewWarrantyRepair::class, ['record' => $case->getRouteKey()])->assertForbidden();
    }

    public function test_authorized_operational_edit_preserves_received_at_sla_and_inventory(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $inventory = ProductInventory::factory()->create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'available_quantity' => 4, 'reserved_quantity' => 1, 'damaged_quantity' => 2, 'average_cost' => '100.0000']);
        $case = $this->warranty($owner, $product, $warehouse, '2026-08-20 09:45:24');
        $beforeInventory = $inventory->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $originalReceivedAt = $case->received_at;
        $this->actingAs($owner);

        Livewire::test(EditWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->assertSee('Received At')
            ->assertSee('03-Sep-2026 09:45:24 AM')
            ->fillForm([
                'expected_return_at' => '2026-08-25 10:00:00',
                'service_provider' => 'Authorized Technician',
                'external_service_reference' => 'SERVICE-77',
                'serial_number' => 'SERIAL-77',
                'received_from' => 'Amazon customer',
                'issue_description' => 'Updated operational issue description',
                'notes' => 'Operational note',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $case->refresh();
        $this->assertTrue($case->received_at->equalTo($originalReceivedAt));
        $this->assertSame('2026-08-25 10:00:00', $case->expected_return_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-03 09:45:24', app(WarrantySlaService::class)->dueAt($case)->format('Y-m-d H:i:s'));
        $this->assertSame($beforeInventory, $inventory->refresh()->only(array_keys($beforeInventory)));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_expected_return_before_received_at_is_shown_as_form_validation(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $case = $this->warranty($owner, $product, $warehouse, '2026-08-20 09:45:24');
        $this->actingAs($owner);

        Livewire::test(EditWarrantyRepair::class, ['record' => $case->getRouteKey()])
            ->fillForm(['expected_return_at' => '2026-08-19 09:45:24'])
            ->call('save')
            ->assertHasFormErrors(['expected_return_at']);

        $this->assertNull($case->refresh()->expected_return_at);
    }

    public function test_staff_cannot_open_operational_edit_page_by_default(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $case = $this->warranty($owner, $product, $warehouse, '2026-08-20 09:45:24');
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->actingAs($staff);

        Livewire::test(EditWarrantyRepair::class, ['record' => $case->getRouteKey()])->assertForbidden();
    }

    public function test_warranty_sla_is_automatic_due_soon_overdue_and_frozen_at_completion(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $received = CarbonImmutable::parse('2026-08-01 10:00:00');
        $case = WarrantyRepair::query()->create(['reference' => 'WR-2026-990001', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => WarrantyRepairSource::Manual, 'issue_description' => 'SLA test', 'received_at' => $received, 'status' => WarrantyRepairStatus::Received, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
        $sla = app(WarrantySlaService::class);

        $this->assertSame('2026-08-15 10:00:00', $sla->dueAt($case)->format('Y-m-d H:i:s'));
        $this->assertSame('On Track', $sla->status($case, CarbonImmutable::parse('2026-08-10')));
        $this->assertSame('Due Soon', $sla->status($case, CarbonImmutable::parse('2026-08-12')));
        $this->assertSame('Overdue', $sla->status($case, CarbonImmutable::parse('2026-08-16')));
        $this->assertSame('1 day overdue', $sla->daysLeftLabel($case, CarbonImmutable::parse('2026-08-16')));
        $case->forceFill(['expected_return_at' => '2026-09-01 10:00:00', 'completed_at' => '2026-08-14 10:00:00'])->save();
        $this->assertSame('2026-08-15 10:00:00', $sla->dueAt($case->refresh())->format('Y-m-d H:i:s'));
        $this->assertSame('Completed Within SLA', $sla->status($case, CarbonImmutable::parse('2026-09-30')));
        $this->assertSame(1, $sla->daysLeft($case, CarbonImmutable::parse('2026-09-30')));
        $case->forceFill(['completed_at' => '2026-08-16 10:00:00'])->save();
        $this->assertSame('SLA Breached', $sla->status($case->refresh()));
    }

    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $platform = MarketplacePlatform::factory()->create();

        return [$owner, $product, $warehouse, $platform];
    }

    private function warranty(User $owner, Product $product, Warehouse $warehouse, string $receivedAt): WarrantyRepair
    {
        return WarrantyRepair::query()->create(['reference' => 'WR-2026-990077', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1, 'source' => WarrantyRepairSource::Manual, 'issue_description' => 'Operational edit test', 'received_at' => $receivedAt, 'status' => WarrantyRepairStatus::Received, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
    }

    /** @param list<Product> $products */
    private function order(User $owner, Warehouse $warehouse, MarketplacePlatform $platform, array $products): Order
    {
        $order = Order::query()->create(['reference' => 'SO-2026-990001', 'source' => 'marketplace', 'status' => 'fulfilled', 'warehouse_id' => $warehouse->id, 'marketplace_platform_id' => $platform->id, 'external_order_number' => 'AMZ-ORDER-77', 'order_date' => now()->toDateString(), 'subtotal' => 0, 'discount_total' => 0, 'vat_total' => 0, 'grand_total' => 0, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id]);
        foreach ($products as $product) {
            OrderItem::query()->create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'ordered_quantity' => 2, 'selling_price' => 0, 'discount_total' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 0]);
        }

        return $order;
    }
}
