<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\ChangeResponsibilityScope;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityTransferTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_transfer_clears_historical_fingerprint_and_creates_linked_successor(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $successor = app(TransferResponsibilityAssignment::class)->handle($source, new TransferResponsibilityAssignmentData($destination->employee->id, 'Handover', (string) Str::uuid()), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Transferred, $source->refresh()->status);
        $this->assertNull($source->active_fingerprint);
        $this->assertNotNull($source->ended_at);
        $this->assertSame($source->id, $successor->predecessor_assignment_id);
        $this->assertSame($destination->employee->id, $successor->employee_id);
        $this->assertNotNull($successor->active_fingerprint);
    }

    public function test_quantity_change_supersedes_and_deactivation_releases_fingerprint_for_reuse(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5]), $f['owner']);
        $successor = app(ChangeResponsibilityQuantity::class)->handle($source, new ChangeResponsibilityQuantityData(7, 'Updated allocation', (string) Str::uuid()), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertNull($source->active_fingerprint);
        $this->assertSame(7, $successor->quantityScope->assigned_quantity);

        app(DeactivateResponsibilityAssignment::class)->handle($successor, new DeactivateResponsibilityAssignmentData('Responsibility ended'), $f['owner']);
        $this->assertSame(ResponsibilityAssignmentStatus::Inactive, $successor->refresh()->status);
        $this->assertNull($successor->active_fingerprint);

        $replacement = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, ResponsibilityAssignmentMode::Quantity, ['assignedQuantity' => 5]), $f['owner']);
        $this->assertSame(3, ResponsibilityAssignment::query()->count());
        $this->assertNotNull($replacement->active_fingerprint);
    }

    public function test_scope_change_from_new_to_renewed_creates_linked_immutable_successor(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['condition' => ProductCondition::New]), $f['owner']);
        $data = $this->changeData($source->employee_id, ['condition' => ProductCondition::Renewed], 'Condition correction');

        $successor = app(ChangeResponsibilityScope::class)->handle($source, $data, $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertNotNull($source->ended_at);
        $this->assertSame($f['owner']->id, $source->ended_by_user_id);
        $this->assertSame($source->id, $successor->predecessor_assignment_id);
        $this->assertSame(ProductCondition::Renewed, $successor->conditionScope->product_condition);
        $this->assertSame('Condition correction', $successor->reason);
        $this->assertNotNull($successor->effective_at);
        $this->assertSame($f['employee']->id, $successor->employee_id);
    }

    public function test_scope_change_rejects_a_broader_assignment_conflict_without_ending_source(): void
    {
        $f = $this->responsibilityFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $sourceBrand = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => $sourceBrand->id]), $f['owner']);
        $existing = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $second->employee->id]), $f['owner']);
        $data = $this->changeData($source->employee_id, ['brandId' => $f['brand']->id, 'condition' => ProductCondition::Renewed], 'Change to renewed');

        try {
            app(ChangeResponsibilityScope::class)->handle($source, $data, $f['owner']);
            $this->fail('An overlapping broad active Responsibility must block this change.');
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString($second->employee->name, $message);
            $this->assertStringContainsString($existing->reference, $message);
            $this->assertStringContainsString('Brand HP', $message);
        }

        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertCount(0, $source->successors);
    }

    public function test_unrelated_category_scopes_do_not_conflict_and_active_creation_is_checked(): void
    {
        $f = $this->responsibilityFoundation();
        $firstCategory = ProductCategory::factory()->create(['name' => 'Laptops']);
        $secondCategory = ProductCategory::factory()->create(['name' => 'Monitors']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'categoryId' => $firstCategory->id]), $f['owner']);

        $candidate = $this->assignmentData($f, overrides: ['employeeId' => $this->responsibilityUser(EmployeeRole::Staff)->employee->id, 'brandId' => null, 'categoryId' => $secondCategory->id]);
        app(ResponsibilityAssignmentService::class)->validateForCreate($candidate);
        $created = app(CreateResponsibilityAssignment::class)->handle($candidate, $f['owner']);

        $this->assertSame($secondCategory->id, $created->categoryScope->product_category_id);
    }

    public function test_product_change_detects_brand_conflict_and_brand_change_to_safe_brand_is_allowed(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $dell = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => $dell->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id]), $f['owner']);

        $this->expectException(ValidationException::class);
        app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id, [
            'brandId' => null,
            'productId' => $f['product']->id,
        ], 'Move to HP product'), $f['owner']);
    }

    public function test_specific_warehouse_brand_change_detects_overlap(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $sourceWarehouse = Warehouse::factory()->create(['status' => true, 'name' => 'Secondary']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['warehouseId' => $sourceWarehouse->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'warehouseId' => $f['inventory']->warehouse_id]), $f['owner']);

        try {
            app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id, ['brandId' => $f['brand']->id, 'warehouseId' => $f['inventory']->warehouse_id], 'Change warehouse'), $f['owner']);
            $this->fail('The conflicting warehouse scope must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($other->employee->name, collect($exception->errors())->flatten()->implode(' '));
        }

    }

    public function test_platform_change_transfers_operational_scope_without_changing_stock(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon', 'normalized_name' => 'noon', 'code' => 'noon']);
        $before = [$f['inventory']->refresh()->available_quantity, $f['inventory']->reserved_quantity, InventoryAllocationBalance::query()->count()];

        $successor = app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id, ['platformId' => $noon->id], 'Move platform'), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertSame($noon->id, $successor->platformScope->marketplace_platform_id);
        $this->assertSame($before, [$f['inventory']->refresh()->available_quantity, $f['inventory']->reserved_quantity, InventoryAllocationBalance::query()->count()]);
    }

    public function test_specific_brand_platform_change_conflicts_with_the_current_product_scope_holder(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon', 'normalized_name' => 'noon', 'code' => 'noon']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['platformId' => $noon->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'platformId' => $f['platform']->id]), $f['owner']);

        try {
            app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id, ['brandId' => $f['brand']->id, 'platformId' => $f['platform']->id], 'Transfer platform'), $f['owner']);
            $this->fail('The existing platform holder must be reported.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($other->employee->name, collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
    }

    public function test_exclusive_category_without_platform_conflicts_include_every_holder_independent_of_assignment_order(): void
    {
        $f = $this->responsibilityFoundation();
        $dell = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $hpHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $dellHolder = $this->responsibilityUser(EmployeeRole::Staff);
        $proposedHolder = $this->responsibilityUser(EmployeeRole::Staff);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $hpHolder->employee->id, 'platformId' => $f['platform']->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $dellHolder->employee->id, 'brandId' => $dell->id, 'platformId' => $f['platform']->id]), $f['owner']);

        try {
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $proposedHolder->employee->id, 'brandId' => null, 'categoryId' => $f['product']->category_id]), $f['owner']);
            $this->fail('An exclusive Category-only scope must conflict with both matching brand holders.');
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString($hpHolder->employee->name, $messages);
            $this->assertStringContainsString($dellHolder->employee->name, $messages);
        }
    }

    public function test_platform_only_transfer_is_operational_and_does_not_move_stock(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $before = [$f['inventory']->refresh()->available_quantity, $f['inventory']->reserved_quantity, InventoryAllocationBalance::query()->count()];

        $successor = app(TransferResponsibilityAssignment::class)->handle($source, new TransferResponsibilityAssignmentData($destination->employee->id, 'Operational marketplace handover', (string) Str::uuid()), $f['owner']);

        $this->assertSame(ResponsibilityAssignmentStatus::Transferred, $source->refresh()->status);
        $this->assertSame($f['platform']->id, $successor->platformScope->marketplace_platform_id);
        $this->assertSame($before, [$f['inventory']->refresh()->available_quantity, $f['inventory']->reserved_quantity, InventoryAllocationBalance::query()->count()]);
    }

    public function test_assign_stock_default_change_is_versioned_and_rejects_another_physical_holder(): void
    {
        $f = $this->responsibilityFoundation();
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $sourceBrand = ProductBrand::factory()->create(['name' => 'Dell', 'normalized_name' => 'dell']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => $sourceBrand->id]), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['employeeId' => $second->employee->id, 'assignStockByDefault' => true]), $f['owner']);

        try {
            app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id, ['brandId' => $f['brand']->id, 'assignStockByDefault' => true], 'Set holder'), $f['owner']);
            $this->fail('A second default holder for overlapping stock must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($second->employee->name, collect($exception->errors())->flatten()->implode(' '));
        }

        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertFalse($source->assign_stock_by_default);
    }

    public function test_default_stock_brand_and_category_scopes_are_intersections_for_receipts(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $laptops = ProductCategory::query()->findOrFail($f['product']->category_id);
        $monitors = ProductCategory::factory()->create(['name' => 'Monitors']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['categoryId' => $laptops->id, 'assignStockByDefault' => true]), $f['owner']);

        $candidate = $this->assignmentData($f, overrides: ['employeeId' => $other->employee->id, 'categoryId' => $monitors->id, 'assignStockByDefault' => true]);
        $monitorAssignment = app(CreateResponsibilityAssignment::class)->handle($candidate, $f['owner']);

        $monitor = Product::factory()->create([
            'brand' => 'HP',
            'brand_id' => $f['brand']->id,
            'category' => 'Monitors',
            'category_id' => $monitors->id,
        ]);
        $monitorInventory = ProductInventory::factory()->create([
            'product_id' => $monitor->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
        ]);
        $laptopAccount = app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null)[0];
        $monitorAccount = app(InventoryAllocationPolicyService::class)->receiptAccount($monitorInventory, null)[0];

        $this->assertSame($f['employee']->id, $laptopAccount->employee_id);
        $this->assertSame($other->employee->id, $monitorAccount->employee_id);
        $this->assertSame($other->employee->id, $monitorAssignment->employee_id);
        $this->assertNotSame($laptops->id, $monitors->id);
    }

    public function test_same_employee_platform_assignments_are_not_false_holder_conflicts(): void
    {
        $f = $this->responsibilityFoundation();
        $amazon = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $f['platform']->id]), $f['owner']);
        $noon = MarketplacePlatform::factory()->create(['name' => 'Noon', 'normalized_name' => 'noon', 'code' => 'noon']);
        $otherPlatform = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => null, 'platformId' => $noon->id]), $f['owner']);

        $this->assertSame($amazon->employee_id, $otherPlatform->employee_id);
        $this->assertSame(2, ResponsibilityAssignment::query()->active()->count());
    }

    public function test_staff_cannot_change_responsibility_scope_through_the_action(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        $this->expectException(AuthorizationException::class);
        app(ChangeResponsibilityScope::class)->handle($source, $this->changeData($source->employee_id), $staff);
    }

    private function changeData(int $employeeId, array $overrides = [], string $reason = 'Scope change'): ChangeResponsibilityScopeData
    {
        return new ChangeResponsibilityScopeData(
            employeeId: $overrides['employeeId'] ?? $employeeId,
            brandId: array_key_exists('brandId', $overrides) ? $overrides['brandId'] : null,
            productId: $overrides['productId'] ?? null,
            categoryId: $overrides['categoryId'] ?? null,
            condition: $overrides['condition'] ?? null,
            warehouseId: $overrides['warehouseId'] ?? null,
            platformId: $overrides['platformId'] ?? null,
            assignStockByDefault: $overrides['assignStockByDefault'] ?? false,
            reason: $reason,
            idempotencyKey: (string) Str::uuid(),
        );
    }
}
