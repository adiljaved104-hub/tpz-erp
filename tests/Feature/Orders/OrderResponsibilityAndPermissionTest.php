<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Orders\OrderReadService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class OrderResponsibilityAndPermissionTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_staff_requires_an_active_matching_responsibility(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        $warehouse = $foundation['inventory']->warehouse;

        try {
            app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $warehouse->id, $staff, null), $staff);
            $this->fail('Unassigned Staff must not reserve an Order.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 0);
        }

        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        $order = app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $warehouse->id, $staff, null), $staff);
        $this->assertSame($staff->id, $order->created_by_user_id);
    }

    public function test_brand_and_platform_combination_requires_both_dimensions(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        $warehouse = $foundation['inventory']->warehouse;
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation, ResponsibilityAssignmentMode::Scope, [
            'platformId' => $foundation['platform']->id,
        ]), $foundation['owner']);

        $order = app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $warehouse->id, $staff, $foundation['platform']), $staff);
        $this->assertSame($foundation['platform']->id, $order->marketplace_platform_id);

        $otherPlatform = MarketplacePlatform::factory()->create();
        $this->expectException(ValidationException::class);
        app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $warehouse->id, $staff, $otherPlatform), $staff);
    }

    public function test_quantity_responsibility_is_scope_only_and_does_not_cap_sales_quantity(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation, ResponsibilityAssignmentMode::Quantity, [
            'assignedQuantity' => 1,
        ]), $foundation['owner']);
        $data = $this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, null, 4);

        $order = app(SaveAndReserveOrder::class)->handle($data, $staff);

        $this->assertSame(4, $order->items()->sole()->ordered_quantity);
        $this->assertSame(4, $foundation['inventory']->refresh()->reserved_quantity);
    }

    public function test_overlapping_active_scopes_do_not_duplicate_order_rows(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation, ResponsibilityAssignmentMode::Scope, [
            'brandId' => null,
            'productId' => $foundation['product']->id,
            'idempotencyKey' => (string) Str::uuid(),
        ]), $foundation['owner']);
        app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, null), $staff);

        $this->assertSame(1, app(OrderReadService::class)->orders($staff)->count());
    }

    public function test_inactive_employee_is_denied_server_side(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        $foundation['employee']->forceFill(['status' => false])->save();

        $this->expectException(AuthorizationException::class);
        app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, null), $staff);
    }

    public function test_order_fulfill_permission_is_required_server_side(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation), $foundation['owner']);
        $foundation['inventory']->forceFill(['average_cost' => '100.0000'])->save();

        $this->expectException(AuthorizationException::class);
        app(SaveAsShippedOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, null), $staff);
    }

    public function test_future_granted_fulfill_permission_still_enforces_responsibility_on_both_paths(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $staff = $foundation['employee']->user;
        $foundation['inventory']->forceFill(['average_cost' => '100.0000'])->save();
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation, overrides: [
            'platformId' => $foundation['platform']->id,
        ]), $foundation['owner']);
        $this->grantFulfillTo($staff);

        $reserved = app(SaveAndReserveOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, $foundation['platform']), $staff);
        $this->actingAs($staff);
        Livewire::test(ViewOrder::class, ['record' => $reserved->getRouteKey()])
            ->assertSee('Save as Shipped')
            ->assertDontSee('Average Cost')
            ->assertDontSee('COGS')
            ->assertDontSee('Profit');
        $fulfilled = app(FulfillOrder::class)->handle($reserved, (string) Str::uuid(), $staff);
        $this->assertSame('fulfilled', $fulfilled->status->value);

        $otherPlatform = MarketplacePlatform::factory()->create();

        try {
            app(SaveAsShippedOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $staff, $otherPlatform), $staff);
            $this->fail('A Platform outside the active Responsibility Assignment must be denied.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('orders', 1);
        }

        $unrelatedBrand = ProductBrand::factory()->create(['name' => 'Unrelated', 'normalized_name' => 'unrelated']);
        $unrelated = Product::factory()->create(['brand' => 'Unrelated', 'brand_id' => $unrelatedBrand->id]);
        ProductInventory::factory()->create([
            'product_id' => $unrelated->id,
            'warehouse_id' => $foundation['inventory']->warehouse_id,
            'available_quantity' => 5,
            'average_cost' => '100.0000',
        ]);

        $this->expectException(ValidationException::class);
        app(SaveAsShippedOrder::class)->handle($this->data($unrelated, $foundation['inventory']->warehouse_id, $staff, $foundation['platform']), $staff);
    }

    public function test_manager_requires_an_explicit_fulfill_grant_and_matching_responsibility(): void
    {
        $foundation = $this->responsibilityFoundation(10);
        $manager = $foundation['employee']->user;
        $foundation['employee']->forceFill(['role' => 'manager'])->save();
        $manager->refresh();
        $foundation['inventory']->forceFill(['average_cost' => '100.0000'])->save();
        app(ResponsibilityAssignmentService::class)->create($this->assignmentData($foundation, overrides: [
            'platformId' => $foundation['platform']->id,
        ]), $foundation['owner']);

        try {
            app(SaveAsShippedOrder::class)->handle($this->data($foundation['product'], $foundation['inventory']->warehouse_id, $manager, $foundation['platform']), $manager);
            $this->fail('A Manager without an explicit fulfilment grant must be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('orders', 0);
        }

        $this->grantFulfillTo($manager);

        $order = app(SaveAsShippedOrder::class)->handle(
            $this->data($foundation['product'], $foundation['inventory']->warehouse_id, $manager, $foundation['platform']),
            $manager,
        );

        $this->assertSame('fulfilled', $order->status->value);
        $this->assertDatabaseCount('order_fulfillments', 1);
    }

    private function data(Product $product, int $warehouseId, $user, ?MarketplacePlatform $platform, int $quantity = 1): SaveAndReserveOrderData
    {
        return new SaveAndReserveOrderData(
            $warehouseId,
            $platform?->id,
            $platform === null ? null : 'TEST-'.Str::random(8),
            now()->toDateString(),
            $user->employee->id,
            null,
            [new OrderItemData($product->id, $quantity, '250.00')],
            (string) Str::uuid(),
        );
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
