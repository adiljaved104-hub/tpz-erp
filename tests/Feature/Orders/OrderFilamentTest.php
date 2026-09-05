<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\SaveAndReserveOrder;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

class OrderFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_selector_uses_compact_server_side_search_guidance_and_stock_labels(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $product->update([
            'name' => 'A deliberately long laptop product title for compact order search results',
        ]);
        ProductInventory::query()->where('product_id', $product->id)->update(['reserved_quantity' => 3]);
        $this->actingAs($owner);

        $component = Livewire::test(CreateOrder::class);
        $this->assertSame($warehouse->id, (int) $component->instance()->form->getRawState()['warehouse_id']);
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertInstanceOf(Select::class, $productField);
        $this->assertSame('Search by SKU or product name', $productField->getPlaceholder());
        $this->assertSame('Type at least 2 characters to search available products.', (string) $productField->getSearchPrompt());
        $this->assertSame('No sellable products found for the selected warehouse/platform.', (string) $productField->getNoSearchResultsMessage());
        $this->assertSame([], $productField->getSearchResults('T'));

        $results = $productField->getSearchResults('deliberately');
        $this->assertArrayHasKey($product->id, $results);
        $this->assertStringStartsWith("{$product->sku} · A deliberately long laptop product title", $results[$product->id]);
        $this->assertStringEndsWith('· Sellable: 7', $results[$product->id]);
        $component->assertSee('Only products with available sellable stock are shown.');
    }

    public function test_create_page_uses_the_simple_save_and_reserve_workflow(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateOrder::class)
            ->assertSee('Platform')
            ->assertSee('External / Marketplace Order Number')
            ->assertSee('Handled By')
            ->assertSee('Add Product')
            ->assertSee('Save & Reserve')
            ->assertSee('Save as Shipped')
            ->assertSeeHtml('wire:submit="create"')
            ->assertDontSee('Average Cost')
            ->assertDontSee('COGS')
            ->fillForm($this->validFormData($owner, $product, $warehouse, quantity: 2))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertSame('reserved', Order::query()->sole()->status->value);
    }

    public function test_save_as_shipped_action_posts_through_the_same_simple_form(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        ProductInventory::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->update(['average_cost' => '100.0000']);
        $this->actingAs($owner);

        $component = Livewire::test(CreateOrder::class)
            ->fillForm($this->validFormData($owner, $product, $warehouse));
        $saveAsShipped = ['name' => 'saveAsShipped', 'context' => ['schemaComponent' => 'content.form-actions']];

        $component->mountAction($saveAsShipped)
            ->assertActionMounted($saveAsShipped);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_fulfillments', 0);

        $component->callMountedAction()
            ->assertHasNoFormErrors();

        $this->assertSame('fulfilled', Order::query()->sole()->status->value);
        $this->assertDatabaseCount('order_fulfillments', 1);
        $this->assertDatabaseCount('inventory_reservations', 0);

        $component->callMountedAction();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    public function test_domain_field_errors_are_mapped_to_filament_form_paths_and_state_is_preserved(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $platform = MarketplacePlatform::factory()->create();
        $this->actingAs($owner);

        $this->mock(SaveAndReserveOrder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(ValidationException::withMessages([
                'items.0.product_id' => 'The selected Product is not available.',
                'items.0.quantity' => 'The requested quantity is not available.',
                'marketplace_platform_id' => 'The selected Platform is not available.',
            ]));
        });

        $data = $this->validFormData($owner, $product, $warehouse, platform: $platform, quantity: 3);

        Livewire::test(CreateOrder::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasErrors([
                'data.items.0.product_id',
                'data.items.0.quantity',
                'data.marketplace_platform_id',
            ])
            ->assertFormSet([
                'marketplace_platform_id' => $platform->id,
                'handled_by_employee_id' => $owner->employee->id,
                'items.0.product_id' => $product->id,
                'items.0.quantity' => 3,
                'items.0.selling_price' => '300.00',
            ]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_insufficient_stock_shows_a_danger_notification_and_creates_nothing(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);

        Livewire::test(CreateOrder::class)
            ->fillForm($this->validFormData($owner, $product, $warehouse, quantity: 11))
            ->call('create')
            ->assertNotified('Order could not be saved')
            ->assertFormSet([
                'items.0.product_id' => $product->id,
                'items.0.quantity' => 11,
                'items.0.selling_price' => '300.00',
            ]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_responsibility_scope_failure_is_visible_on_the_product_field(): void
    {
        [, $product, $warehouse] = $this->foundation();
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->actingAs($staff->refresh());

        Livewire::test(CreateOrder::class)
            ->fillForm($this->validFormData($staff, $product, $warehouse))
            ->call('create')
            ->assertHasErrors(['data.items.0.product_id']);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_unavailable_fulfilment_location_business_rule_is_shown_on_the_field(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);

        $this->mock(SaveAndReserveOrder::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(ValidationException::withMessages([
                'warehouse_id' => 'The selected Warehouse is not available.',
            ]));
        });

        Livewire::test(CreateOrder::class)
            ->fillForm($this->validFormData($owner, $product, $warehouse))
            ->call('create')
            ->assertHasErrors(['data.warehouse_id']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_save_as_shipped_validation_failure_is_visible_and_creates_nothing(): void
    {
        [$owner, $product, $warehouse] = $this->foundation();
        $this->actingAs($owner);
        $saveAsShipped = ['name' => 'saveAsShipped', 'context' => ['schemaComponent' => 'content.form-actions']];

        Livewire::test(CreateOrder::class)
            ->fillForm($this->validFormData($owner, $product, $warehouse, quantity: 11))
            ->mountAction($saveAsShipped)
            ->callMountedAction()
            ->assertNotified('Order could not be saved');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_fulfillments', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_save_as_shipped_handler_is_not_publicly_invokable_by_livewire(): void
    {
        $method = new ReflectionMethod(CreateOrder::class, 'createShipped');

        $this->assertFalse($method->isPublic());
    }

    public function test_orders_resource_and_direct_pages_are_server_side_authorized(): void
    {
        [$owner] = $this->foundation();
        $inactive = User::factory()->create();
        Employee::factory()->for($inactive)->role(EmployeeRole::Staff)->inactive()->create(['email' => $inactive->email]);

        $this->actingAs($owner);
        $this->assertTrue(OrderResource::canViewAny());
        Livewire::test(ListOrders::class)->assertOk();

        $this->actingAs($inactive->refresh());
        $this->assertFalse(OrderResource::canViewAny());
        Livewire::test(CreateOrder::class)->assertForbidden();
    }

    public function test_staff_does_not_receive_the_fulfilment_action_by_default(): void
    {
        $this->foundation();
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $this->actingAs($staff->refresh());

        Livewire::test(CreateOrder::class)
            ->assertSee('Save & Reserve')
            ->assertDontSee('Save as Shipped');
    }

    public function test_explicitly_granted_staff_and_manager_receive_fulfilment_without_financial_fields(): void
    {
        $this->foundation();
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $manager = User::factory()->create();
        Employee::factory()->for($manager)->role(EmployeeRole::Manager)->create(['email' => $manager->email]);
        $this->grantFulfillTo($staff, $manager);

        foreach ([$staff->refresh(), $manager->refresh()] as $user) {
            $this->actingAs($user);

            Livewire::test(CreateOrder::class)
                ->assertSee('Save as Shipped')
                ->assertDontSee('Average Cost')
                ->assertDontSee('COGS')
                ->assertDontSee('Profit');
        }
    }

    /** @return array{User, Product, Warehouse} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $warehouse = Warehouse::query()->where('status', true)->where('is_default', true)->sole();
        $product = Product::factory()->create(['selling_price' => '300.00']);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
        ]);

        return [$owner->refresh(), $product, $warehouse];
    }

    /** @return array<string, mixed> */
    private function validFormData(
        User $actor,
        Product $product,
        Warehouse $warehouse,
        ?MarketplacePlatform $platform = null,
        int $quantity = 1,
    ): array {
        return [
            'warehouse_id' => $warehouse->id,
            'marketplace_platform_id' => $platform?->id,
            'external_order_number' => $platform === null ? null : 'TEST-ORDER-1',
            'order_date' => now()->toDateString(),
            'handled_by_employee_id' => $actor->employee->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'selling_price' => '300.00',
            ]],
        ];
    }

    private function grantFulfillTo(User ...$users): void
    {
        $owner = Employee::query()->where('role', EmployeeRole::Owner->value)->sole()->user;

        foreach ($users as $user) {
            app(EmployeePermissionOverrideService::class)->change(
                $user->employee,
                OrderPermission::Fulfill->value,
                EmployeePermissionEffect::Allow,
                null,
                $owner,
            );
        }
    }
}
