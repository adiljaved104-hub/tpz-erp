<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Enums\OrderStatus;
use App\Filament\Resources\CustomerReturns\Pages\CreateCustomerReturn;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\ExternalOrderIdentityService;
use App\Services\Orders\OrderReferenceSearchService;
use App\Services\Orders\OrderService;
use App\Services\Search\GlobalSearchService;
use App\Services\ServiceCases\ServiceCaseOrderContextService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OrderReferenceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_search_finds_both_references_with_normalized_input_and_consistent_labels(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $order = $this->order($owner, $warehouse, $platform, 'SO-2026-000123', 'AMZ-AbC-123');
        $references = app(OrderReferenceSearchService::class);

        $this->assertSame($order->id, $references->search($owner, '  so-2026-000123  ')->sole()->id);
        $this->assertSame($order->id, $references->search($owner, '  amz-abc-123  ')->sole()->id);
        $this->assertSame('SO-2026-000123 — Amazon UAE — AMZ-AbC-123', $references->label($order));
        $this->assertSame($order->id, $references->findAuthorized($owner, ' amz-abc-123 ')?->id);

        $manual = $this->order($owner, $warehouse, null, 'SO-2026-000124', null);
        $this->assertSame('SO-2026-000124 — Manual / No Platform', $references->label($manual));
    }

    public function test_duplicate_identity_is_platform_scoped_and_safe_error_identifies_only_authorized_conflicts(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $product = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
        ]);
        $amazon = MarketplacePlatform::factory()->create();
        $noon = MarketplacePlatform::factory()->create();
        $first = app(SaveAndReserveOrder::class)->handle(
            $this->orderData($owner, $warehouse, $product, $amazon, '  EXT-500  '),
            $owner,
        );

        try {
            app(SaveAndReserveOrder::class)->handle(
                $this->orderData($owner, $warehouse, $product, $amazon, 'ext-500'),
                $owner,
            );
            $this->fail('The normalized duplicate should have been rejected.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['external_order_number'][0];
            $this->assertStringContainsString($first->reference, $message);
        }

        $otherPlatform = app(SaveAndReserveOrder::class)->handle(
            $this->orderData($owner, $warehouse, $product, $noon, 'ext-500'),
            $owner,
        );
        $this->assertNotSame($first->id, $otherPlatform->id);
        $this->assertSame('EXT-500', $first->external_order_number);

        $hash = app(ExternalOrderIdentityService::class)->hash($amazon->id, ' ext-500 ');
        $safeMessage = app(OrderReferenceSearchService::class)->duplicateValidationMessage($manager, $hash);
        $this->assertSame('This Platform Order Number already exists.', $safeMessage);
        $this->assertStringNotContainsString($first->reference, $safeMessage);
    }

    public function test_draft_edit_uses_the_same_normalization_and_ignores_its_own_identity(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create(['status' => true, 'is_default' => true]);
        $product = Product::factory()->create();
        $platform = MarketplacePlatform::factory()->create();
        $service = app(OrderService::class);
        $draft = $service->saveDraft($this->orderData($owner, $warehouse, $product, $platform, '  Draft-Ext-1  '), $owner);

        $updated = $service->saveDraft(
            $this->orderData($owner, $warehouse, $product, $platform, 'DRAFT-EXT-1'),
            $owner,
            $draft,
        );

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame('DRAFT-EXT-1', $updated->external_order_number);
        $this->assertSame(
            app(ExternalOrderIdentityService::class)->hash($platform->id, 'draft-ext-1'),
            $updated->external_identity_hash,
        );
    }

    public function test_global_and_service_case_searches_preserve_order_authorization_scope(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $product = Product::factory()->create();
        $order = $this->order($owner, $warehouse, $platform, 'SO-SCOPED-900', 'NOON-SCOPED-900', $product);

        $ownerResults = app(GlobalSearchService::class)->search($owner, 'NOON-SCOPED-900')->get('Orders');
        $this->assertSame($order->id, (int) str($ownerResults->sole()->url)->afterLast('/')->toString());
        $this->assertNull(app(GlobalSearchService::class)->search($manager, 'NOON-SCOPED-900')->get('Orders'));

        $context = app(ServiceCaseOrderContextService::class);
        $this->assertArrayHasKey($order->id, $context->searchOrders('SO-SCOPED-900', $owner));
        $this->assertArrayHasKey($order->id, $context->searchOrders('noon-scoped-900', $owner));
        $this->assertArrayNotHasKey($order->id, $context->searchOrders('NOON-SCOPED-900', $manager));
        $this->assertSame('SO-SCOPED-900 — Noon UAE — NOON-SCOPED-900', $context->orderLabel($order));
    }

    public function test_customer_return_selector_uses_server_side_dual_reference_search_for_fulfilled_orders_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $fulfilled = $this->order($owner, $warehouse, $platform, 'SO-RETURN-001', 'AMZ-RETURN-001', status: OrderStatus::Fulfilled);
        $draft = $this->order($owner, $warehouse, $platform, 'SO-RETURN-002', 'AMZ-RETURN-002');
        $this->actingAs($owner);

        $component = Livewire::test(CreateCustomerReturn::class);
        $field = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'order_id');

        $this->assertInstanceOf(Select::class, $field);
        $results = $field->getSearchResults('SO-RETURN-001');
        $this->assertArrayHasKey($fulfilled->id, $results);
        $this->assertSame('SO-RETURN-001 — Amazon UAE — AMZ-RETURN-001', $results[$fulfilled->id]);
        $this->assertArrayHasKey($fulfilled->id, $field->getSearchResults('amz-return-001'));
        $this->assertArrayNotHasKey($draft->id, $field->getSearchResults('AMZ-RETURN-002'));
    }

    private function user(EmployeeRole $role): User
    {
        $employee = Employee::factory()->role($role)->create(['status' => true]);

        return $employee->user->refresh();
    }

    private function order(
        User $owner,
        Warehouse $warehouse,
        ?MarketplacePlatform $platform,
        string $reference,
        ?string $external,
        ?Product $product = null,
        OrderStatus $status = OrderStatus::Draft,
    ): Order {
        $order = Order::query()->create([
            'reference' => $reference,
            'source' => $platform ? 'marketplace' : 'manual',
            'status' => $status,
            'warehouse_id' => $warehouse->id,
            'marketplace_platform_id' => $platform?->id,
            'external_order_number' => $external,
            'external_identity_hash' => app(ExternalOrderIdentityService::class)->hash($platform?->id, $external),
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'discount_total' => 0,
            'vat_total' => 0,
            'grand_total' => 0,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $owner->id,
        ]);

        if ($product) {
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'ordered_quantity' => 1,
                'selling_price' => 100,
                'discount_total' => 0,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'line_total' => 100,
            ]);
        }

        return $order;
    }

    private function orderData(
        User $actor,
        Warehouse $warehouse,
        Product $product,
        MarketplacePlatform $platform,
        string $external,
    ): SaveAndReserveOrderData {
        return new SaveAndReserveOrderData(
            warehouseId: $warehouse->id,
            platformId: $platform->id,
            externalOrderNumber: $external,
            orderDate: now()->toDateString(),
            handledByEmployeeId: $actor->employee->id,
            notes: null,
            items: [new OrderItemData($product->id, 1, '100.00')],
            idempotencyKey: (string) Str::uuid(),
        );
    }
}
