<?php

namespace Tests\Feature\Mobile;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\Quotation;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileBatch6CommercialOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_creation_component_management_and_intelligence_reuse_domain_services(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $brand = ProductBrand::factory()->create(['status' => true]);
        $category = ProductCategory::factory()->create(['status' => true]);
        $payload = [
            'name' => 'Mobile Intelligence Laptop', 'brand_id' => $brand->id, 'category_id' => $category->id,
            'condition' => 'new', 'model' => 'MI-16', 'processor_class' => 'Core i7',
            'processor_model' => '1360P', 'processor_generation' => '13th Gen', 'ram' => '16GB',
            'storage' => '512GB', 'touch_screen' => true, 'is_convertible_360' => false,
            'warranty' => 12, 'selling_price' => '2500.00', 'cost_price' => '1800.0000',
            'description' => 'Created through the authorized Mobile product workflow.',
        ];
        $created = $this->as($owner)->postJson('/api/mobile/v1/workspace/products', $payload)
            ->assertOk()->assertJsonPath('data.fields.processor_model', '1360P')
            ->assertJsonPath('data.fields.selling_price', '2500.00');
        $product = Product::query()->findOrFail($created->json('data.id'));
        $this->assertNotNull($product->sku);
        $this->assertSame($owner->id, $product->created_by_user_id);

        $this->as($owner)->putJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/profile', [
            'ram_upgradeable' => true, 'max_supported_ram_mb' => 32768,
            'storage_upgradeable' => true, 'notes' => 'Mobile profile', 'slots' => [],
        ])->assertOk()->assertJsonPath('data.hardware_profile.version', 1);
        $configuration = $this->as($owner)->postJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/configurations', [
            'display_name' => '32GB / 1TB', 'target_ram_mb' => 32768, 'target_storage_total_gb' => 1024,
            'target_storage_layout' => [], 'suggested_selling_addon' => 300, 'default_selling_price' => 2800,
            'active' => true,
        ])->assertOk()->assertJsonPath('data.configurations.0.display_name', '32GB / 1TB');
        $configurationId = $configuration->json('data.configurations.0.id');
        $this->as($owner)->putJson('/api/mobile/v1/workspace/products/'.$product->id.'/intelligence/configurations/'.$configurationId, [
            'display_name' => '32GB / 1TB Mobile', 'target_ram_mb' => 32768, 'target_storage_total_gb' => 1024,
            'target_storage_layout' => [], 'suggested_selling_addon' => 325, 'default_selling_price' => 2825,
            'active' => true,
        ])->assertOk()->assertJsonPath('data.configurations.0.display_name', '32GB / 1TB Mobile');

        $component = $this->as($owner)->postJson('/api/mobile/v1/workspace/components', [
            'name' => 'Mobile 16GB RAM', 'brand_id' => $brand->id, 'category_id' => $category->id,
            'component_type' => 'ram', 'specification' => 'DDR4 3200 16GB',
            'capacity_value' => 16, 'capacity_unit' => 'gb', 'interface_type' => 'SODIMM',
            'approved_oem_recovery_value' => '20.0000', 'recovery_reason' => 'Approved reusable component value',
        ])->assertOk()->assertJsonPath('data.fields.approved_oem_recovery_value', '20.0000');
        $this->as($this->user(EmployeeRole::Manager))->getJson('/api/mobile/v1/workspace/components/'.$component->json('data.id'))
            ->assertOk()->assertJsonMissingPath('data.fields.approved_oem_recovery_value');
    }

    public function test_quick_purchase_receives_stock_atomically_and_replay_is_user_bound(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $otherOwner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $owner->employee->id, mode: ResponsibilityAssignmentMode::Scope,
            brandId: $brand->id, platformId: null, productId: null, productInventoryId: null,
            assignedQuantity: null, effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Default quick purchase allocation', notes: null, idempotencyKey: (string) Str::uuid(),
            warehouseId: $warehouse->id, assignStockByDefault: true,
        ), $owner);
        $key = (string) Str::uuid();
        $payload = [
            'warehouse_id' => $warehouse->id, 'purchase_date' => now()->toDateString(),
            'idempotency_key' => $key, 'items' => [['product_id' => $product->id, 'ordered_quantity' => 3, 'unit_cost' => '125.00']],
        ];
        $response = $this->as($owner)->postJson('/api/mobile/v1/workspace/purchases/quick', $payload)
            ->assertOk()->assertJsonPath('data.status', 'fully_received');
        $purchase = Purchase::query()->findOrFail($response->json('data.id'));
        $this->assertSame('quick_stock', $purchase->entry_type->value);
        $this->assertSame(3, ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sole()->available_quantity);
        $this->assertSame(1, PurchaseReceipt::query()->where('idempotency_key', $key)->count());

        $this->as($owner)->postJson('/api/mobile/v1/workspace/purchases/quick', $payload)->assertOk();
        $this->as($otherOwner)->postJson('/api/mobile/v1/workspace/purchases/quick', $payload)->assertForbidden();
        $this->assertSame(3, ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->sole()->available_quantity);
    }

    public function test_reserved_order_amendment_adjusts_reservation_and_protects_idempotency_owner(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $otherOwner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $product = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 5, 'reserved_quantity' => 0, 'damaged_quantity' => 0,
        ]);
        $order = $this->as($owner)->postJson('/api/mobile/v1/workspace/orders', [
            'mode' => 'reserve', 'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'selling_price' => '500.00', 'discount_total' => 0, 'vat_rate' => 0]],
        ])->assertOk();
        $orderId = $order->json('data.id');
        $sourceId = $order->json('data.items.0.allocation_source_options.0.id');
        $this->assertNotNull($sourceId);
        $item = Order::query()->findOrFail($orderId)->items()->sole();
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key, 'reason' => 'Customer requested a second unit',
            'items' => [[
                'id' => $item->id, 'quantity' => 2, 'selling_price' => '475.00',
                'allocation_sources' => [['account_id' => $sourceId, 'quantity' => 1]],
            ]],
        ];
        $this->as($owner)->postJson('/api/mobile/v1/workspace/orders/'.$orderId.'/amend', $payload)
            ->assertOk()->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.selling_price', '475.00');
        $this->assertSame(2, InventoryReservation::query()->where('order_item_id', $item->id)->sole()->quantity);
        $this->assertSame($owner->id, OrderAmendment::query()->where('idempotency_key', $key)->sole()->amended_by_user_id);
        $this->as($otherOwner)->postJson('/api/mobile/v1/workspace/orders/'.$orderId.'/amend', $payload)->assertForbidden();
    }

    public function test_quotation_and_invoice_mobile_workflows_preserve_scope_and_immutable_transitions(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $outsider = $this->user(EmployeeRole::Staff);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        CompanyProfile::query()->create([
            'id' => 1, 'company_name_en' => 'Tech Point Zone', 'trn' => '100000000000001',
            'address_en' => 'Dubai UAE', 'updated_by_user_id' => $owner->id,
        ]);
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 5, 'reserved_quantity' => 0, 'damaged_quantity' => 0,
        ]);
        app(CreateResponsibilityAssignment::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: $staff->employee->id, mode: ResponsibilityAssignmentMode::Scope,
            brandId: $brand->id, platformId: null, productId: null, productInventoryId: null,
            assignedQuantity: null, effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Authorized quotation product scope', notes: null, idempotencyKey: (string) Str::uuid(),
            warehouseId: $warehouse->id,
        ), $owner);
        $quotePayload = [
            'warehouse_id' => $warehouse->id, 'document_type' => 'quotation',
            'quotation_date' => now()->toDateString(), 'valid_until' => now()->addWeek()->toDateString(),
            'customer_name' => 'Mobile Commercial Customer', 'customer_phone' => '+971500000000',
            'idempotency_key' => (string) Str::uuid(), 'items' => [[
                'source_type' => 'existing_product', 'product_id' => $product->id, 'description' => $product->name,
                'quantity' => 1, 'unit_price_including_vat' => '1050.00', 'discount_amount' => '0', 'vat_rate' => '5',
            ]],
        ];
        $quote = $this->as($staff)->postJson('/api/mobile/v1/workspace/quotations', $quotePayload)
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $quotationId = $quote->json('data.id');
        $this->as($outsider)->getJson('/api/mobile/v1/workspace/quotations/'.$quotationId)->assertForbidden();
        $this->as($staff)->postJson('/api/mobile/v1/workspace/quotations/'.$quotationId.'/send')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/quotations/'.$quotationId.'/accept')->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/quotations/'.$quotationId.'/convert-order', [
            'warehouse_id' => $warehouse->id, 'customer_phone' => '+971500000000', 'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.status', 'converted');
        $this->assertNotNull(Quotation::query()->findOrFail($quotationId)->order_id);

        $invoicePayload = [
            'customer_name' => 'Invoice Customer', 'customer_address' => 'Dubai', 'customer_phone' => '+971500000001',
            'order_reference' => 'MOBILE-COMMERCIAL', 'invoice_date' => now()->toDateString(),
            'title_mode' => 'auto', 'terms_profile' => 'auto', 'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Authorized mobile invoice item', 'quantity' => 1, 'unit_price_including_vat' => '525.00']],
        ];
        $invoice = $this->as($owner)->postJson('/api/mobile/v1/workspace/invoices', $invoicePayload)
            ->assertOk()->assertJsonPath('data.status', 'issued');
        $invoiceId = $invoice->json('data.id');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/invoices/'.$invoiceId.'/edit-customer', [
            'customer_name' => 'Corrected Customer', 'customer_address' => 'Abu Dhabi',
            'amendment_reason' => 'Correct customer details',
        ])->assertOk()->assertJsonPath('data.fields.customer_name', 'Corrected Customer');
        $this->as($owner)->postJson('/api/mobile/v1/workspace/invoices/'.$invoiceId.'/void', [
            'reason' => 'Duplicate invoice issued in testing',
        ])->assertOk()->assertJsonPath('data.status', 'void');
        $this->assertSame('void', TaxInvoice::query()->findOrFail($invoiceId)->status);
    }

    private function user(EmployeeRole $role): User
    {
        $email = 'mobile-batch6-'.Str::lower(Str::random(12)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email, 'status' => true]);

        return $user->refresh();
    }

    private function as(User $user): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($user->createToken('Mobile Batch 6 test')->plainTextToken);
    }
}
