<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\EmployeeRole;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment as CreateResponsibilityAssignmentPage;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityCategoryAndBulkTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_category_is_dynamic_and_platform_aware(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'categoryId' => $category->id, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $laterBrand = ProductBrand::factory()->create();
        $later = Product::factory()->create(['brand_id' => $laterBrand->id, 'brand' => $laterBrand->name, 'category_id' => $category->id, 'category' => $category->name]);
        $otherCategory = ProductCategory::factory()->create();
        $unrelated = Product::factory()->create(['category_id' => $otherCategory->id, 'category' => $otherCategory->name]);
        $staff = $f['employee']->user;
        $warehouseId = $f['inventory']->warehouse_id;

        $this->assertSame($category->id, $assignment->categoryScope->product_category_id);
        $this->assertTrue(app(OrderResponsibilityScopeService::class)->canAccessProduct($staff, $later->id, $f['platform']->id, $warehouseId));
        $this->assertFalse(app(OrderResponsibilityScopeService::class)->canAccessProduct($staff, $later->id, null, $warehouseId));
        $this->assertFalse(app(OrderResponsibilityScopeService::class)->canAccessProduct($staff, $unrelated->id, $f['platform']->id, $warehouseId));
        $this->assertTrue(app(ResponsibilityProductScopeService::class)->canAccessProduct($staff, $later->id));
    }

    public function test_category_duplicate_transfer_deactivate_and_audit_are_supported(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $data = $this->assignmentData($f, overrides: ['brandId' => null, 'categoryId' => $category->id]);
        $source = app(CreateResponsibilityAssignment::class)->handle($data, $f['owner']);
        $destination = $this->responsibilityUser(EmployeeRole::Staff);
        $successor = app(TransferResponsibilityAssignment::class)->handle($source, new TransferResponsibilityAssignmentData(
            $destination->employee->id, 'Category handover', (string) Str::uuid(),
        ), $f['owner']);

        $this->assertSame($category->id, $successor->categoryScope->product_category_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'responsibility.transferred', 'subject_id' => $successor->id]);
        app(DeactivateResponsibilityAssignment::class)->handle($successor, new DeactivateResponsibilityAssignmentData('Category ended'), $f['owner']);
        $replacement = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $destination->employee->id, 'brandId' => null, 'categoryId' => $category->id,
        ]), $f['owner']);
        $this->assertNotNull($replacement->active_fingerprint);
    }

    public function test_category_and_brand_scope_is_an_intersection_and_remains_dynamic(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $otherBrand = ProductBrand::factory()->create();
        $otherCategory = ProductCategory::factory()->create();
        $matching = Product::factory()->create([
            'brand_id' => $f['brand']->id,
            'brand' => $f['brand']->name,
            'category_id' => $category->id,
            'category' => $category->name,
        ]);
        $wrongBrand = Product::factory()->create([
            'brand_id' => $otherBrand->id,
            'brand' => $otherBrand->name,
            'category_id' => $category->id,
            'category' => $category->name,
        ]);
        $wrongCategory = Product::factory()->create([
            'brand_id' => $f['brand']->id,
            'brand' => $f['brand']->name,
            'category_id' => $otherCategory->id,
            'category' => $otherCategory->name,
        ]);
        $matchingInventory = ProductInventory::factory()->create([
            'product_id' => $matching->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
        ]);
        $wrongBrandInventory = ProductInventory::factory()->create([
            'product_id' => $wrongBrand->id,
            'warehouse_id' => $f['inventory']->warehouse_id,
        ]);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => $f['brand']->id, 'categoryId' => $category->id,
        ]), $f['owner']);
        $staff = $f['employee']->user;
        $scope = app(OrderResponsibilityScopeService::class);
        $productScope = app(ResponsibilityProductScopeService::class);

        $this->assertTrue($scope->canAccessProduct($staff, $matching->id, null, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($staff, $wrongBrand->id, null, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($staff, $wrongCategory->id, null, $f['inventory']->warehouse_id));
        $this->assertTrue($productScope->canAccessProduct($staff, $matching->id));
        $this->assertFalse($productScope->canAccessProduct($staff, $wrongBrand->id));
        $this->assertFalse($productScope->canAccessProduct($staff, $wrongCategory->id));
        $this->assertTrue($productScope->canAccessInventory($staff, $matchingInventory->id));
        $this->assertFalse($productScope->canAccessInventory($staff, $wrongBrandInventory->id));

        $later = Product::factory()->create([
            'brand_id' => $f['brand']->id,
            'brand' => $f['brand']->name,
            'category_id' => $category->id,
            'category' => $category->name,
        ]);
        $this->assertTrue($scope->canAccessProduct($staff, $later->id, null, $f['inventory']->warehouse_id));
    }

    public function test_category_brand_and_platform_scope_requires_all_three_dimensions(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => $f['brand']->id,
            'categoryId' => $category->id,
            'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $scope = app(OrderResponsibilityScopeService::class);
        $otherPlatform = MarketplacePlatform::factory()->create();

        $this->assertTrue($scope->canAccessProduct($f['employee']->user, $f['product']->id, $f['platform']->id, $f['inventory']->warehouse_id));
        $this->assertFalse($scope->canAccessProduct($f['employee']->user, $f['product']->id, $otherPlatform->id, $f['inventory']->warehouse_id));
    }

    public function test_multi_brand_category_batch_creates_exact_assignments_and_retry_is_idempotent(): void
    {
        $f = $this->responsibilityFoundation();
        $category = $f['product']->categoryRelation;
        $second = ProductBrand::factory()->create();
        $batch = $this->batch($f, 'brand', [$f['brand']->id, $second->id], $f['platform']->id, $category->id);

        $first = app(BulkResponsibilityAssignmentService::class)->create($batch, $f['owner']);
        $retry = app(BulkResponsibilityAssignmentService::class)->create($batch, $f['owner']);

        $this->assertCount(2, $first);
        $this->assertCount(2, $retry);
        $this->assertSame(2, ResponsibilityAssignment::query()->count());
        $this->assertEqualsCanonicalizing([$f['brand']->id, $second->id], $first->pluck('brandScope.product_brand_id')->all());
        $this->assertTrue($first->every(fn ($assignment) => $assignment->categoryScope->product_category_id === $category->id));
        $this->assertTrue($first->every(fn ($assignment) => $assignment->platformScope->marketplace_platform_id === $f['platform']->id));
    }

    public function test_category_brand_form_normalization_preserves_exact_intersection_values(): void
    {
        $scope = CreateResponsibilityAssignmentPage::normalizedScope([
            'scope_type' => 'category_brand_platform',
            'brand_ids' => [9, 4, 9],
            'category_id' => 7,
            'platform_id' => 3,
        ]);

        $this->assertSame([9, 4], $scope['brand_ids']);
        $this->assertSame(7, $scope['category_id']);
        $this->assertSame(3, $scope['platform_id']);
        $this->assertSame([], $scope['product_ids']);
    }

    public function test_bulk_brands_create_exact_assignments_and_retry_is_idempotent(): void
    {
        $f = $this->responsibilityFoundation();
        $second = ProductBrand::factory()->create();
        $batch = $this->batch($f, 'brand', [$f['brand']->id, $second->id, $second->id], $f['platform']->id);

        $first = app(BulkResponsibilityAssignmentService::class)->create($batch, $f['owner']);
        $retry = app(BulkResponsibilityAssignmentService::class)->create($batch, $f['owner']);

        $this->assertCount(2, $first);
        $this->assertCount(2, $retry);
        $this->assertSame(2, ResponsibilityAssignment::query()->count());
        $this->assertSame(2, $first->pluck('reference')->unique()->count());
        $this->assertTrue($first->every(fn ($assignment) => $assignment->platformScope->marketplace_platform_id === $f['platform']->id));
    }

    public function test_bulk_products_are_exact_and_existing_duplicate_causes_no_partial_write(): void
    {
        $f = $this->responsibilityFoundation();
        $second = Product::factory()->create();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'brandId' => null, 'productId' => $f['product']->id,
        ]), $f['owner']);

        try {
            app(BulkResponsibilityAssignmentService::class)->create($this->batch($f, 'product', [$f['product']->id, $second->id]), $f['owner']);
            $this->fail('An existing exact assignment must reject the whole batch.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($f['product']->sku, $exception->getMessage());
        }

        $this->assertSame(1, ResponsibilityAssignment::query()->count());
        $this->assertDatabaseMissing('responsibility_assignment_products', ['product_id' => $second->id]);
    }

    private function batch(array $f, string $type, array $ids, ?int $platformId = null, ?int $categoryId = null): CreateResponsibilityAssignmentBatchData
    {
        return new CreateResponsibilityAssignmentBatchData(
            employeeId: $f['employee']->id,
            scopeType: $type,
            scopeIds: $ids,
            categoryId: $categoryId,
            platformId: $platformId,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Bulk operational scope',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
        );
    }
}
