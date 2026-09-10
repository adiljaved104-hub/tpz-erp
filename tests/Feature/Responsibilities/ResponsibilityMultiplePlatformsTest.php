<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment as CreateResponsibilityAssignmentPage;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ResponsibilityAssignment;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityMultiplePlatformsTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_brand_and_three_platforms_create_three_exact_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(3)->create();

        $created = $this->createBatch($f, 'brand', [$f['brand']->id], $platforms->modelKeys());

        $this->assertCount(3, $created);
        $this->assertSame(3, $created->pluck('reference')->unique()->count());
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->brandScope->product_brand_id === $f['brand']->id));
        $this->assertEqualsCanonicalizing($platforms->modelKeys(), $created->pluck('platformScope.marketplace_platform_id')->all());
    }

    public function test_three_platform_only_scopes_create_three_exact_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(3)->create();

        $created = $this->createBatch($f, 'platform', [], $platforms->modelKeys());

        $this->assertCount(3, $created);
        $this->assertEqualsCanonicalizing($platforms->modelKeys(), $created->pluck('platformScope.marketplace_platform_id')->all());
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->brandScope === null && $assignment->categoryScope === null && $assignment->productScope === null));
    }

    public function test_two_brands_and_three_platforms_create_six_exact_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $brands = collect([$f['brand'], ProductBrand::factory()->create()]);
        $platforms = MarketplacePlatform::factory()->count(3)->create();

        $created = $this->createBatch($f, 'brand', $brands->pluck('id')->all(), $platforms->modelKeys());

        $this->assertCount(6, $created);
        $this->assertSame(6, $created->pluck('active_fingerprint')->unique()->count());
        $this->assertSame(6, $created->map(fn ($assignment): string => $assignment->brandScope->product_brand_id.':'.$assignment->platformScope->marketplace_platform_id)->unique()->count());
    }

    public function test_category_and_two_platforms_create_two_dynamic_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(2)->create();

        $created = $this->createBatch($f, 'category', [], $platforms->modelKeys(), $f['product']->category_id);

        $this->assertCount(2, $created);
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->categoryScope->product_category_id === $f['product']->category_id));
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->brandScope === null && $assignment->productScope === null));
    }

    public function test_category_two_brands_and_three_platforms_create_six_intersections(): void
    {
        $f = $this->responsibilityFoundation();
        $secondBrand = ProductBrand::factory()->create();
        $platforms = MarketplacePlatform::factory()->count(3)->create();

        $created = $this->createBatch(
            $f,
            'brand',
            [$f['brand']->id, $secondBrand->id],
            $platforms->modelKeys(),
            $f['product']->category_id,
        );

        $this->assertCount(6, $created);
        $this->assertTrue($created->every(fn ($assignment): bool => $assignment->categoryScope->product_category_id === $f['product']->category_id));
        $this->assertSame(6, $created->map(fn ($assignment): string => $assignment->brandScope->product_brand_id.':'.$assignment->platformScope->marketplace_platform_id)->unique()->count());
    }

    public function test_two_products_and_two_platforms_create_four_exact_assignments(): void
    {
        $f = $this->responsibilityFoundation();
        $products = collect([$f['product'], Product::factory()->create()]);
        $platforms = MarketplacePlatform::factory()->count(2)->create();

        $created = $this->createBatch($f, 'product', $products->pluck('id')->all(), $platforms->modelKeys());

        $this->assertCount(4, $created);
        $this->assertSame(4, $created->map(fn ($assignment): string => $assignment->productScope->product_id.':'.$assignment->platformScope->marketplace_platform_id)->unique()->count());
    }

    public function test_duplicate_combination_rejects_the_entire_expansion_transactionally(): void
    {
        $f = $this->responsibilityFoundation();
        $platforms = MarketplacePlatform::factory()->count(2)->create();
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'platformId' => $platforms[0]->id,
        ]), $f['owner']);

        try {
            $this->createBatch($f, 'brand', [$f['brand']->id], $platforms->modelKeys());
            $this->fail('An active exact combination must reject the whole expanded batch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope_ids', $exception->errors());
        }

        $this->assertSame(1, ResponsibilityAssignment::query()->count());
        $this->assertDatabaseMissing('responsibility_assignment_platforms', ['marketplace_platform_id' => $platforms[1]->id]);
    }

    public function test_invalid_platform_id_fails_server_side_without_partial_creation(): void
    {
        $f = $this->responsibilityFoundation();
        $valid = MarketplacePlatform::factory()->create();

        try {
            $this->createBatch($f, 'brand', [$f['brand']->id], [$valid->id, 999999]);
            $this->fail('Every Platform must be validated by the service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('platform_ids', $exception->errors());
        }

        $this->assertDatabaseCount('responsibility_assignments', 0);
    }

    public function test_quantity_platform_remains_single_select_and_allocation_is_not_multiplied(): void
    {
        $f = $this->responsibilityFoundation();
        $this->actingAs($f['owner']);
        $component = Livewire::test(CreateResponsibilityAssignmentPage::class);
        $fields = collect($component->instance()->form->getFlatFields(withHidden: true));
        $platforms = $fields->first(fn ($field): bool => $field->getName() === 'platform_ids');
        $quantityPlatform = $fields->first(fn ($field): bool => $field->getName() === 'platform_id');

        $this->assertInstanceOf(Select::class, $platforms);
        $this->assertTrue($platforms->isMultiple());
        $this->assertInstanceOf(Select::class, $quantityPlatform);
        $this->assertFalse($quantityPlatform->isMultiple());

        $assignment = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData(
            $f,
            ResponsibilityAssignmentMode::Quantity,
            ['platformId' => $f['platform']->id, 'assignedQuantity' => 4],
        ), $f['owner']);

        $this->assertSame(4, $assignment->quantityScope->assigned_quantity);
        $this->assertSame($f['platform']->id, $assignment->platformScope->marketplace_platform_id);
        $this->assertDatabaseCount('responsibility_assignments', 1);
    }

    public function test_existing_single_platform_batch_input_remains_idempotent(): void
    {
        $f = $this->responsibilityFoundation();
        $data = $this->batchData($f, 'brand', [$f['brand']->id], [], platformId: $f['platform']->id);

        $first = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);
        $retry = app(BulkResponsibilityAssignmentService::class)->create($data, $f['owner']);

        $this->assertCount(1, $first);
        $this->assertCount(1, $retry);
        $this->assertSame($first->first()->id, $retry->first()->id);
        $this->assertDatabaseCount('responsibility_assignments', 1);
    }

    public function test_form_normalization_accepts_multiple_platforms_and_keeps_quantity_single(): void
    {
        $normal = CreateResponsibilityAssignmentPage::normalizedScope([
            'scope_type' => 'category_brand_platform',
            'brand_ids' => [8, 9],
            'category_id' => 4,
            'platform_ids' => [3, 7, 3],
        ]);
        $quantity = CreateResponsibilityAssignmentPage::normalizedScope([
            'scope_type' => 'quantity_platform',
            'platform_id' => 5,
            'platform_ids' => [3, 7],
            'product_inventory_id' => 11,
            'assigned_quantity' => 2,
        ]);

        $this->assertSame([3, 7], $normal['platform_ids']);
        $this->assertSame(3, $normal['platform_id']);
        $this->assertSame([], $quantity['platform_ids']);
        $this->assertSame(5, $quantity['platform_id']);
    }

    private function createBatch(array $foundation, string $scopeType, array $scopeIds, array $platformIds, ?int $categoryId = null)
    {
        return app(BulkResponsibilityAssignmentService::class)->create(
            $this->batchData($foundation, $scopeType, $scopeIds, $platformIds, $categoryId),
            $foundation['owner'],
        );
    }

    private function batchData(array $foundation, string $scopeType, array $scopeIds, array $platformIds, ?int $categoryId = null, ?int $platformId = null): CreateResponsibilityAssignmentBatchData
    {
        return new CreateResponsibilityAssignmentBatchData(
            employeeId: $foundation['employee']->id,
            scopeType: $scopeType,
            scopeIds: $scopeIds,
            categoryId: $categoryId,
            platformId: $platformId,
            effectiveAt: now()->subMinute()->toDateTimeString(),
            reason: 'Multi-platform operational scope',
            notes: null,
            idempotencyKey: (string) Str::uuid(),
            platformIds: $platformIds,
        );
    }
}
