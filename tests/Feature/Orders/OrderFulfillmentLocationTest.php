<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Exceptions\ImmutableOrderException;
use App\Exceptions\InventoryInvariantException;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderFulfillmentLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class OrderFulfillmentLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_options_are_active_non_transit_and_platform_aware(): void
    {
        $main = Warehouse::query()->where('code', 'MAIN')->sole();
        $amazon = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $amazonLocation = $this->marketplaceLocation($amazon, 'Amazon FBA UAE', 'AMZ-FBA');
        $noonLocation = $this->marketplaceLocation($noon, 'Noon FBN UAE', 'NOON-FBN');
        $inactive = Warehouse::factory()->inactive()->create(['name' => 'Inactive', 'code' => 'INACTIVE']);
        $transit = Warehouse::factory()->create([
            'name' => 'Transit',
            'code' => 'TRANSIT',
            'location_type' => InventoryLocationType::Transit,
        ]);

        $service = app(OrderFulfillmentLocationService::class);
        $amazonOptions = $service->options($amazon->id);

        $this->assertSame([$amazonLocation->id, $main->id], array_slice(array_keys($amazonOptions), 0, 2));
        $this->assertArrayNotHasKey($noonLocation->id, $amazonOptions);
        $this->assertArrayNotHasKey($inactive->id, $amazonOptions);
        $this->assertArrayNotHasKey($transit->id, $amazonOptions);
        $this->assertArrayNotHasKey($amazonLocation->id, $service->options(null));
        $this->assertSame($main->id, $service->defaultId());
    }

    public function test_form_defaults_main_and_reacts_to_location_stock_context_without_reloading(): void
    {
        $owner = $this->owner();
        $main = Warehouse::query()->where('code', 'MAIN')->sole();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $otherPlatform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $location = $this->marketplaceLocation($platform, 'Amazon FBA UAE', 'AMZ-FBA');
        $product = Product::factory()->create();
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 20,
            'reserved_quantity' => 4,
            'average_cost' => '100.0000',
        ]);
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $location->id,
            'available_quantity' => 12,
            'reserved_quantity' => 3,
            'average_cost' => '200.0000',
        ]);

        $this->actingAs($owner);
        $component = Livewire::test(CreateOrder::class)
            ->assertSee('Fulfilled From');
        $initialState = $component->instance()->form->getRawState();
        $this->assertSame($main->id, (int) $initialState['warehouse_id']);
        $lineKey = array_key_first($initialState['items']);

        $component
            ->set('data.marketplace_platform_id', (string) $platform->id)
            ->set("data.items.{$lineKey}.product_id", (string) $product->id)
            ->assertSee('Available: 20 | Reserved: 4 | Sellable: 16')
            ->set('data.warehouse_id', (string) $location->id)
            ->assertSee('Available: 12 | Reserved: 3 | Sellable: 9')
            ->set('data.marketplace_platform_id', (string) $otherPlatform->id)
            ->assertSee('Available: 20 | Reserved: 4 | Sellable: 16');

        $state = $component->instance()->form->getRawState();
        $this->assertSame($main->id, (int) $state['warehouse_id']);
        $this->assertSame($product->id, (int) $state['items'][$lineKey]['product_id']);
    }

    public function test_reservation_uses_only_the_selected_location_and_preserves_main_inventory(): void
    {
        [$owner, $product, $main, $platform, $location, $mainInventory, $locationInventory] = $this->foundation();

        $order = app(SaveAndReserveOrder::class)->handle($this->data($owner, $product, $location, $platform, 2), $owner);
        $reservation = $order->items()->sole()->reservation;

        $this->assertSame($location->id, $order->warehouse_id);
        $this->assertSame($location->id, $reservation->warehouse_id);
        $this->assertSame($locationInventory->id, $reservation->product_inventory_id);
        $this->assertSame(0, $mainInventory->refresh()->reserved_quantity);
        $this->assertSame(3, $locationInventory->refresh()->reserved_quantity);
        $this->assertSame(20, $mainInventory->available_quantity);
        $this->assertSame(7, $locationInventory->sellableQuantity());
    }

    public function test_direct_shipping_uses_selected_location_inventory_and_cogs(): void
    {
        [$owner, $product, , $platform, $location, $mainInventory, $locationInventory] = $this->foundation();

        $order = app(SaveAsShippedOrder::class)->handle($this->data($owner, $product, $location, $platform, 2), $owner);
        $fulfillmentItem = $order->fulfillment->items()->sole();

        $this->assertSame($location->id, $order->warehouse_id);
        $this->assertSame($location->id, $fulfillmentItem->warehouse_id);
        $this->assertSame($locationInventory->id, $fulfillmentItem->product_inventory_id);
        $this->assertSame('200.0000', $fulfillmentItem->inventory_unit_cost);
        $this->assertSame('400.0000', $fulfillmentItem->cogs_total);
        $this->assertSame(20, $mainInventory->refresh()->available_quantity);
        $this->assertSame(8, $locationInventory->refresh()->available_quantity);
        $this->assertSame(1, $locationInventory->reserved_quantity);
    }

    public function test_different_platform_location_is_rejected_and_missing_inventory_is_not_created(): void
    {
        $owner = $this->owner();
        $main = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $amazon = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $amazonLocation = $this->marketplaceLocation($amazon, 'Amazon FBA UAE', 'AMZ-FBA');
        $noonLocation = $this->marketplaceLocation($noon, 'Noon FBN UAE', 'NOON-FBN');
        ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 10,
            'average_cost' => '100.0000',
        ]);

        try {
            app(SaveAndReserveOrder::class)->handle($this->data($owner, $product, $noonLocation, $amazon), $owner);
            $this->fail('A Marketplace location linked to another Platform must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('warehouse_id', $exception->errors());
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('product_inventories', 1);
        $this->assertDatabaseMissing('product_inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $noonLocation->id,
        ]);

        try {
            app(SaveAndReserveOrder::class)->handle($this->data($owner, $product, $amazonLocation, $amazon), $owner);
            $this->fail('Selecting a valid location with no ProductInventory must treat stock as zero.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Only 0 sellable units', $exception->errors()['items'][0]);
        }

        $this->assertDatabaseMissing('product_inventories', [
            'product_id' => $product->id,
            'warehouse_id' => $amazonLocation->id,
        ]);

        $order = app(SaveAndReserveOrder::class)->handle($this->data($owner, $product, $main, $amazon), $owner);
        $this->assertSame($main->id, $order->warehouse_id);
        $this->assertSame($main->id, $order->items()->sole()->reservation->warehouse_id);
    }

    public function test_reserved_location_is_immutable_and_fulfilment_requires_matching_reservation_location(): void
    {
        [$owner, $product, $main, $platform, $location, , $locationInventory] = $this->foundation();
        $order = app(SaveAndReserveOrder::class)->handle($this->data($owner, $product, $location, $platform, 2), $owner);

        try {
            $order->update(['warehouse_id' => $main->id]);
            $this->fail('A Reserved Order location must be immutable.');
        } catch (ImmutableOrderException) {
            $this->assertSame($location->id, $order->fresh()->warehouse_id);
        }

        $reservation = $order->items()->sole()->reservation;
        $reservation->forceFill(['warehouse_id' => $main->id])->save();

        try {
            app(FulfillOrder::class)->handle($order->fresh(), (string) Str::uuid(), $owner);
            $this->fail('Fulfilment must reject a reservation from another location.');
        } catch (InventoryInvariantException) {
            $this->assertSame(10, $locationInventory->refresh()->available_quantity);
            $this->assertSame(3, $locationInventory->reserved_quantity);
            $this->assertDatabaseCount('order_fulfillments', 0);
        }
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return $user->refresh();
    }

    private function marketplaceLocation(MarketplacePlatform $platform, string $name, string $code): Warehouse
    {
        return Warehouse::factory()->create([
            'name' => $name,
            'code' => $code,
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => $platform->id,
            'status' => true,
        ]);
    }

    /** @return array{User, Product, Warehouse, MarketplacePlatform, Warehouse, ProductInventory, ProductInventory} */
    private function foundation(): array
    {
        $owner = $this->owner();
        $main = Warehouse::query()->where('code', 'MAIN')->sole();
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon UAE']);
        $location = $this->marketplaceLocation($platform, 'Amazon FBA UAE', 'AMZ-FBA');
        $product = Product::factory()->create();
        $mainInventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $main->id,
            'available_quantity' => 20,
            'reserved_quantity' => 0,
            'average_cost' => '100.0000',
        ]);
        $locationInventory = ProductInventory::factory()->create([
            'product_id' => $product->id,
            'warehouse_id' => $location->id,
            'available_quantity' => 10,
            'reserved_quantity' => 1,
            'average_cost' => '200.0000',
        ]);

        return [$owner, $product, $main, $platform, $location, $mainInventory, $locationInventory];
    }

    private function data(
        User $actor,
        Product $product,
        Warehouse $location,
        ?MarketplacePlatform $platform,
        int $quantity = 1,
    ): SaveAndReserveOrderData {
        return new SaveAndReserveOrderData(
            $location->id,
            $platform?->id,
            $platform === null ? null : 'LOCATION-TEST-'.Str::random(8),
            now()->toDateString(),
            $actor->employee->id,
            null,
            [new OrderItemData($product->id, $quantity, '300.00')],
            (string) Str::uuid(),
        );
    }
}
