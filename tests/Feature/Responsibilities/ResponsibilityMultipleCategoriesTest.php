<?php

namespace Tests\Feature\Responsibilities;

use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment;
use App\Models\MarketplacePlatform;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ResponsibilityAssignment;
use App\Services\Inventory\InventoryAllocationPolicyService;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityMultipleCategoriesTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public static function platformCounts(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('platformCounts')]
    public function test_categories_expand_exactly_and_shared_scope_can_coexist_without_default_ownership(int $platformCount): void
    {
        $f = $this->responsibilityFoundation();
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        $platforms = MarketplacePlatform::factory()->count($platformCount)->create()->modelKeys();
        $data = $this->batch($f['employee']->id, $categories, $platforms);
        $service = app(BulkResponsibilityAssignmentService::class);
        $first = $service->create($data, $f['owner']);
        $retry = $service->create($data, $f['owner']);
        $this->assertCount(3 * $platformCount, $first);
        $this->assertEqualsCanonicalizing($first->pluck('id')->all(), $retry->pluck('id')->all());
        $this->assertSame($first->count(), $first->pluck('reference')->unique()->count());
        $this->assertSame($first->count(), $first->pluck('active_fingerprint')->unique()->count());
        $this->assertEqualsCanonicalizing($categories, $first->pluck('categoryScope.product_category_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing($platforms, $first->pluck('platformScope.marketplace_platform_id')->unique()->values()->all());
        $this->assertTrue($first->every(fn ($a) => ! $a->assign_stock_by_default && $a->conditionScope->product_condition === ProductCondition::New));
        $second = $this->responsibilityUser(EmployeeRole::Staff);
        $this->assertCount($first->count(), $service->create($this->batch($second->employee->id, $categories, $platforms), $f['owner']));
        $this->assertDatabaseCount('inventory_allocation_balances', 0);
        $this->assertDatabaseCount('inventory_allocation_events', 0);

        try {
            $service->create($this->batch($f['employee']->id, $categories, $platforms), $f['owner']);
            $this->fail('Exact duplicate batch must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_ids', $e->errors());
        }
        $this->assertDatabaseCount('responsibility_assignments', 6 * $platformCount);
    }

    public function test_invalid_category_and_combination_limit_fail_before_any_creation(): void
    {
        $f = $this->responsibilityFoundation();
        $categories = ProductCategory::factory()->count(11)->create()->modelKeys();
        $platforms = MarketplacePlatform::factory()->count(10)->create()->modelKeys();
        foreach ([[$categories, $platforms], [[999999], [$f['platform']->id]]] as [$ids, $platformIds]) {
            try {
                app(BulkResponsibilityAssignmentService::class)->create($this->batch($f['employee']->id, $ids, $platformIds), $f['owner']);
                $this->fail('Invalid batch must not create assignments.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('category_ids', $e->errors());
            }
            $this->assertDatabaseCount('responsibility_assignments', 0);
        }
    }

    public function test_form_multiselect_creates_exact_categories_and_hides_default_stock_control(): void
    {
        $f = $this->responsibilityFoundation();
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        Livewire::actingAs($f['owner'])->test(CreateResponsibilityAssignment::class)
            ->set('data.scope_type', 'condition_category_platform')
            ->assertFormFieldIsHidden('assign_stock_by_default')
            ->fillForm([
                'employee_id' => $f['employee']->id, 'scope_type' => 'condition_category_platform',
                'category_ids' => $categories, 'platform_ids' => [$f['platform']->id], 'condition' => ProductCondition::New->value,
                'effective_at' => now()->subMinute()->toDateTimeString(), 'reason' => 'Shared category operations',
            ])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseCount('responsibility_assignments', 3);
        $this->assertSame(0, ResponsibilityAssignment::query()->where('assign_stock_by_default', true)->count());
    }

    public function test_tampered_default_stock_selection_is_rejected_for_shared_scope(): void
    {
        $f = $this->responsibilityFoundation();
        $this->expectException(ValidationException::class);
        app(BulkResponsibilityAssignmentService::class)->create($this->batch($f['employee']->id, [$f['product']->category_id], [$f['platform']->id], true), $f['owner']);
    }

    public function test_existing_bulk_brands_compose_with_categories_and_platforms(): void
    {
        $f = $this->responsibilityFoundation();
        $brands = ProductBrand::factory()->count(2)->create()->modelKeys();
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        $platforms = MarketplacePlatform::factory()->count(2)->create()->modelKeys();
        $created = app(BulkResponsibilityAssignmentService::class)->create(new CreateResponsibilityAssignmentBatchData(
            employeeId: $f['employee']->id, scopeType: 'brand', scopeIds: $brands, categoryId: null, platformId: null,
            effectiveAt: now()->subMinute()->toDateTimeString(), reason: 'Exact brand category combinations', notes: null,
            idempotencyKey: (string) Str::uuid(), platformIds: $platforms, categoryIds: $categories,
        ), $f['owner']);
        $this->assertCount(12, $created);
        $this->assertSame(12, $created->pluck('idempotency_key')->unique()->count());
        $this->assertSame(12, $created->pluck('active_fingerprint')->unique()->count());
    }

    public function test_legacy_shared_default_flag_is_not_used_as_receipt_ownership(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = app(BulkResponsibilityAssignmentService::class)->create($this->batch($f['employee']->id, [$f['product']->category_id], [$f['platform']->id]), $f['owner'])->sole();
        $assignment->forceFill(['assign_stock_by_default' => true])->save();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No default stock responsibility');
        app(InventoryAllocationPolicyService::class)->receiptAccount($f['inventory'], null);
    }

    private function batch(int $employeeId, array $categoryIds, array $platformIds, bool $default = false): CreateResponsibilityAssignmentBatchData
    {
        return new CreateResponsibilityAssignmentBatchData(
            employeeId: $employeeId, scopeType: 'category', scopeIds: [], categoryId: null, platformId: null,
            effectiveAt: now()->subMinute()->toDateTimeString(), reason: 'Shared category operations', notes: null,
            idempotencyKey: (string) Str::uuid(), platformIds: $platformIds, condition: ProductCondition::New,
            assignStockByDefault: $default, categoryIds: $categoryIds,
        );
    }
}
