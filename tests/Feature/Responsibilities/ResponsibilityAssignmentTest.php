<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeeRole;
use App\Enums\ResponsibilityAssignmentMode;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Exceptions\ImmutableResponsibilityException;
use App\Exceptions\InvalidResponsibilityScopeException;
use App\Exceptions\ResponsibilityCapacityExceededException;
use App\Models\InventoryResponsibilityQuantity;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_every_approved_exact_scope_combination_is_stored_once(): void
    {
        $f = $this->responsibilityFoundation();
        $action = app(CreateResponsibilityAssignment::class);
        $brand = $action->handle($this->assignmentData($f), $f['owner']);
        $platform = $action->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $brandPlatform = $action->handle($this->assignmentData($f, overrides: ['platformId' => $f['platform']->id]), $f['owner']);
        $product = $action->handle($this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id]), $f['owner']);
        $productPlatform = $action->handle($this->assignmentData($f, overrides: ['brandId' => null, 'productId' => $f['product']->id, 'platformId' => $f['platform']->id]), $f['owner']);
        $quantity = $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 3]), $f['owner']);
        $quantityPlatform = $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 2, 'platformId' => $f['platform']->id]), $f['owner']);

        $this->assertSame(7, ResponsibilityAssignment::query()->count());
        $this->assertSame(2, ResponsibilityAssignmentBrand::query()->count());
        $this->assertSame(4, ResponsibilityAssignmentPlatform::query()->count());
        $this->assertSame(2, ResponsibilityAssignmentProduct::query()->count());
        $this->assertSame(2, InventoryResponsibilityQuantity::query()->count());
        $this->assertTrue($brand->reference === 'RA-000001');
        $this->assertNotSame($platform->active_fingerprint, $brandPlatform->active_fingerprint);
        $this->assertNotSame($product->active_fingerprint, $productPlatform->active_fingerprint);
        $this->assertNotSame($quantity->active_fingerprint, $quantityPlatform->active_fingerprint);
    }

    public function test_two_platforms_require_two_assignments_and_active_duplicate_is_rejected(): void
    {
        $f = $this->responsibilityFoundation();
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon UAE', 'normalized_name' => 'noon uae', 'code' => 'noon_uae']);
        $action = app(CreateResponsibilityAssignment::class);
        $action->handle($this->assignmentData($f, overrides: ['platformId' => $f['platform']->id]), $f['owner']);
        $action->handle($this->assignmentData($f, overrides: ['platformId' => $noon->id]), $f['owner']);
        $this->assertSame(2, ResponsibilityAssignment::query()->count());

        $this->expectException(DuplicateActiveResponsibilityException::class);
        $action->handle($this->assignmentData($f, overrides: ['platformId' => $f['platform']->id]), $f['owner']);
    }

    public function test_quantity_aggregate_is_protected_and_physical_inventory_is_unchanged(): void
    {
        $f = $this->responsibilityFoundation(20, 5);
        $before = $f['inventory']->only(['available_quantity', 'reserved_quantity', 'damaged_quantity', 'average_cost']);
        $action = app(CreateResponsibilityAssignment::class);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 8]), $f['owner']);
        $secondUser = $this->responsibilityUser(EmployeeRole::Staff);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['employeeId' => $secondUser->employee->id, 'assignedQuantity' => 7]), $f['owner']);

        $this->assertSame(15, InventoryResponsibilityQuantity::query()->sum('assigned_quantity'));
        $this->assertSame($before, $f['inventory']->refresh()->only(array_keys($before)));
        $this->assertSame(0, StockMovement::query()->count());

        $this->expectException(ResponsibilityCapacityExceededException::class);
        $third = $this->responsibilityUser(EmployeeRole::Staff);
        $action->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['employeeId' => $third->employee->id, 'assignedQuantity' => 1]), $f['owner']);
    }

    public function test_invalid_scope_and_non_positive_quantity_are_rejected(): void
    {
        $f = $this->responsibilityFoundation();

        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => null, 'productId' => null]), $f['owner']);
            $this->fail('Empty scope should fail.');
        } catch (InvalidResponsibilityScopeException) {
            $this->assertSame(0, ResponsibilityAssignment::query()->count());
        }

        $this->expectException(InvalidResponsibilityScopeException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 0, 'idempotencyKey' => (string) Str::uuid()]), $f['owner']);
    }

    public function test_idempotent_retry_creates_one_assignment(): void
    {
        $f = $this->responsibilityFoundation();
        $data = $this->assignmentData($f);
        $first = app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
        $retry = app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ResponsibilityAssignment::query()->count());
    }

    public function test_assignment_cannot_be_hard_deleted(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);

        $this->expectException(ImmutableResponsibilityException::class);
        $assignment->delete();
    }

    public function test_inactive_employee_and_brand_product_mismatch_are_rejected(): void
    {
        $f = $this->responsibilityFoundation();
        $f['employee']->forceFill(['status' => false])->save();

        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
            $this->fail('Inactive Employee must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(0, ResponsibilityAssignment::query()->count());
        }

        $f['employee']->forceFill(['status' => true])->save();
        $otherBrand = ProductBrand::factory()->create();
        $otherProduct = Product::factory()->create(['brand_id' => $otherBrand->id, 'brand' => $otherBrand->name]);
        $this->expectException(ValidationException::class);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['productId' => $otherProduct->id]), $f['owner']);
    }
}
