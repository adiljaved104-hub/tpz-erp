<?php

namespace Tests\Feature\Phase2;

use App\Actions\Inventory\PostOpeningStock;
use App\DTOs\Inventory\PostOpeningStockData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\OpeningStockEntries\OpeningStockEntryResource;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class InventoryFilamentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_render_all_phase_2_inventory_screens(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create();
        app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 2, 1, '5.0000', 'Initial', (string) Str::uuid()), $owner);
        $this->actingAs($owner);

        $this->get(ProductInventoryResource::getUrl())->assertOk()->assertSee('Sellable')->assertSee('Available includes Reserved');
        $this->get(StockMovementResource::getUrl())->assertOk();
        $this->get(OpeningStockEntryResource::getUrl())->assertOk();
        $this->get(OpeningStockEntryResource::getUrl('create'))->assertOk();
        $this->get(InventoryReservationResource::getUrl())->assertOk();
    }

    public function test_staff_cannot_render_inventory_resources(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);

        $this->get(ProductInventoryResource::getUrl())->assertForbidden();
        $this->get(StockMovementResource::getUrl())->assertForbidden();
        $this->get(OpeningStockEntryResource::getUrl())->assertForbidden();
        $this->get(InventoryReservationResource::getUrl())->assertForbidden();
    }

    public function test_location_balances_requires_its_page_permission_and_normal_inventory_view(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);

        $this->actingAs($manager);
        $this->assertTrue(ProductInventoryResource::canViewAny());

        app(EmployeePermissionOverrideService::class)->change(
            $manager->employee,
            InventoryPermission::ViewLocationBalances->value,
            EmployeePermissionEffect::Deny,
            'Focused Location Balances access test',
            $owner,
        );
        $this->actingAs($manager->fresh());
        $this->assertFalse(ProductInventoryResource::canViewAny());

        $this->actingAs($staff->fresh());
        $this->assertFalse(ProductInventoryResource::canViewAny());

        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            InventoryPermission::View->value,
            EmployeePermissionEffect::Allow,
            'Existing Inventory View override remains effective',
            $owner,
        );
        $this->actingAs($staff->fresh());
        $this->assertTrue(ProductInventoryResource::canViewAny());

        app(EmployeePermissionOverrideService::class)->change(
            $staff->employee,
            InventoryPermission::ViewLocationBalances->value,
            EmployeePermissionEffect::Deny,
            'Location Balances may be denied independently',
            $owner,
        );
        $this->actingAs($staff->fresh());
        $this->assertFalse(ProductInventoryResource::canViewAny());
    }

    public function test_stock_movement_table_renders_full_product_context_and_preserves_cost_authorization(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $product = Product::factory()->create([
            'name' => 'A deliberately long stock movement product title that must remain fully readable',
            'model' => 'TPZ-LONG-MODEL',
        ]);
        $warehouse = Warehouse::factory()->create();
        app(PostOpeningStock::class)->handle(new PostOpeningStockData($product->id, $warehouse->id, 2, 0, '25.0000', 'Readable ledger row', (string) Str::uuid()), $owner);
        $this->actingAs($owner);

        $component = Livewire::test(ListStockMovements::class)
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee($product->sku)
            ->assertSee('TPZ-LONG-MODEL');
        $this->assertNotNull($component->instance()->getTable()->getColumn('unit_cost'));

        $staff = $this->user(EmployeeRole::Staff);
        $this->actingAs($staff);
        Livewire::test(ListStockMovements::class)->assertForbidden();
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
