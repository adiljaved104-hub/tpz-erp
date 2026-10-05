<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\ChangeResponsibilityScope;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeBatchData;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Filament\Resources\ResponsibilityAssignments\Pages\ListResponsibilityAssignments;
use App\Models\MarketplacePlatform;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ResponsibilityAssignment;
use App\Services\ActivityLogger;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\ReferenceSequenceService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityScopeChangeCategoriesTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public static function transferModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('transferModes')]
    public function test_three_exact_successors_end_source_once_preserve_stock_and_retry_safely(bool $transfer): void
    {
        $f = $this->responsibilityFoundation(20, 1);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['notes' => 'Historical notes']), $f['owner']);
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        $employeeId = $transfer ? $this->responsibilityUser(EmployeeRole::Staff)->employee->id : $f['employee']->id;
        $allocations = app(InventoryAllocationService::class);
        $allocations->ensureShadowCoverage($f['inventory'], $f['owner']);
        $allocations->reconcile($f['inventory'], $allocations->employeeAccount($f['employee']->id), 2, $f['owner'], 'Existing ownership');
        $before = $this->stockSnapshot();
        $sourceUpdates = 0;
        DB::listen(function ($query) use ($source, &$sourceUpdates): void {
            if (str_starts_with($query->sql, 'update "responsibility_assignments"') && end($query->bindings) === $source->id) {
                $sourceUpdates++;
            }
        });
        $data = $this->batch($f, $categories, ['employeeId' => $employeeId, 'assignStockByDefault' => true]);
        $service = app(ResponsibilityAssignmentService::class);
        $this->assertStringContainsString('SAFE — 3 exact', $service->previewScopeChanges($source, $data));
        $action = app(ChangeResponsibilityScope::class);
        $successors = $action->handleBatch($source, $data, $f['owner']);
        $this->assertCount(3, $successors);
        $this->assertSame(1, $sourceUpdates);
        $this->assertSame($transfer ? ResponsibilityAssignmentStatus::Transferred : ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertNull($source->active_fingerprint);
        $this->assertEqualsCanonicalizing($categories, $successors->pluck('categoryScope.product_category_id')->all());
        $this->assertSame(3, $successors->pluck('reference')->unique()->count());
        $this->assertSame(3, $successors->pluck('active_fingerprint')->unique()->count());
        $this->assertTrue($successors->every(fn ($a) => $a->predecessor_assignment_id === $source->id
            && $a->status === ResponsibilityAssignmentStatus::Active && $a->employee_id === $employeeId
            && $a->brandScope->product_brand_id === $f['brand']->id && $a->conditionScope->product_condition === ProductCondition::Renewed
            && $a->platformScope->marketplace_platform_id === $f['platform']->id && $a->assign_stock_by_default && $a->notes === 'Historical notes'));
        $endedAt = $source->ended_at;
        $referencesBefore = DB::table('reference_sequences')->where('key', 'responsibility_assignment')->value('next_value');
        $retry = $action->handleBatch($source, new ChangeResponsibilityScopeBatchData($data->scope, array_reverse($categories)), $f['owner']);
        $this->assertSame($successors->pluck('id')->all(), $retry->pluck('id')->all());
        $this->assertSame(1, $sourceUpdates);
        $this->assertEquals($endedAt, $source->refresh()->ended_at);
        $this->assertSame($referencesBefore, DB::table('reference_sequences')->where('key', 'responsibility_assignment')->value('next_value'));
        $this->assertSame(3, DB::table('activity_logs')->where('event', 'responsibility.scope_changed')->count());
        $this->assertSame($before, $this->stockSnapshot());
    }

    public static function categoryDefaults(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('categoryDefaults')]
    public function test_modal_categories_are_multiple_active_and_prefilled(bool $hasCategory): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['categoryId' => $hasCategory ? $f['product']->category_id : null]), $f['owner']);
        $inactive = ProductCategory::factory()->create(['status' => false]);
        $component = Livewire::actingAs($f['owner'])->test(ListResponsibilityAssignments::class)
            ->mountTableAction('changeScope', $source)
            ->assertTableActionDataSet(['category_ids' => $hasCategory ? [$f['product']->category_id] : []]);
        $page = $component->instance();
        $fields = collect($page->getSchema($page->getMountedActionSchemaName())->getFlatComponents());
        $field = $fields->first(fn ($field) => $field instanceof Select && $field->getName() === 'category_ids');
        $this->assertNotNull($field);
        $this->assertTrue($field->isMultiple());
        $this->assertSame('Categories', $field->getLabel());
        $this->assertArrayNotHasKey($inactive->id, $field->getOptions());
        $this->assertFalse($fields->contains(fn ($field) => $field instanceof Select && $field->getName() === 'category_id'));
    }

    public function test_modal_submits_three_categories_and_preserves_data_on_category_conflict(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        Livewire::actingAs($f['owner'])->test(ListResponsibilityAssignments::class)
            ->callTableAction('changeScope', $source, $this->formData($f, $categories))
            ->assertHasNoTableActionErrors()->assertNotified('Responsibility changed');
        $this->assertSame(3, $source->successors()->count());

        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => ProductBrand::factory()->create()->id]), $f['owner']);
        $data = $this->formData($f, $categories);
        $component = Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $data)
            ->assertHasTableActionErrors(['scope'])->assertActionMounted(TestAction::make('changeScope')->table($source))
            ->assertTableActionDataSet(['category_ids' => $categories, 'reason' => $data['reason']])
            ->assertNotified('Responsibility was not changed');
        $this->assertStringContainsString(ProductCategory::findOrFail($categories[0])->name, implode(' ', $component->instance()->getErrorBag()->all()));
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_one_conflict_identifies_category_and_blocks_entire_change_before_references(): void
    {
        $f = $this->responsibilityFoundation();
        $f['brand']->update(['name' => 'Apple', 'normalized_name' => 'apple']);
        $categories = ProductCategory::factory()->count(3)->create();
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $other->employee->update(['name' => 'Existing holder']);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Noon']);
        $existing = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $other->employee->id, 'categoryId' => $categories[0]->id, 'platformId' => $platform->id,
            'assignStockByDefault' => true,
        ]), $f['owner']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => ProductBrand::factory()->create()->id]), $f['owner']);
        $data = $this->batch($f, $categories->modelKeys(), ['assignStockByDefault' => true]);
        $snapshot = $this->assignmentSnapshot();
        $preview = app(ResponsibilityAssignmentService::class)->previewScopeChanges($source, $data);
        foreach (['1 of 3', $categories[0]->name, 'Brand Apple', 'Existing holder', $existing->reference, 'no Condition restriction', 'includes Renewed stock', 'Platform Noon does not separate Default Stock ownership'] as $text) {
            $this->assertStringContainsString($text, $preview);
        }
        $this->assertSame($snapshot, $this->assignmentSnapshot());
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
            $this->fail('One blocked Category must prevent all successors.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1 of 3', implode(' ', $e->errors()['scope']));
        }
        $this->assertSame($snapshot, $this->assignmentSnapshot());
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_all_conflicting_categories_are_explained_and_explicit_new_condition_does_not_block_renewed(): void
    {
        $f = $this->responsibilityFoundation();
        $categories = ProductCategory::factory()->count(3)->create();
        foreach ($categories->take(2) as $category) {
            $holder = $this->responsibilityUser(EmployeeRole::Staff);
            app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
                'employeeId' => $holder->employee->id, 'categoryId' => $category->id,
                'condition' => ProductCondition::New, 'platformId' => MarketplacePlatform::factory()->create()->id,
                'assignStockByDefault' => true,
            ]), $f['owner']);
        }
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => ProductBrand::factory()->create()->id]), $f['owner']);
        $service = app(ResponsibilityAssignmentService::class);
        $data = $this->batch($f, $categories->modelKeys(), ['condition' => null, 'assignStockByDefault' => true]);
        $preview = $service->previewScopeChanges($source, $data);
        $this->assertStringContainsString('2 of 3', $preview);
        foreach ($categories->take(2) as $category) {
            $this->assertStringContainsString($category->name, $preview);
        }
        $renewed = $this->batch($f, $categories->modelKeys(), ['assignStockByDefault' => true]);
        $this->assertStringContainsString('SAFE — 3 exact', $service->previewScopeChanges($source, $renewed));
        $this->assertCount(3, app(ChangeResponsibilityScope::class)->handleBatch($source, $renewed, $f['owner']));
    }

    public function test_brand_renewed_scope_without_category_conflicts_with_unrestricted_condition_on_another_platform(): void
    {
        $f = $this->responsibilityFoundation();
        $holder = $this->responsibilityUser(EmployeeRole::Staff);
        $existing = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $holder->employee->id, 'categoryId' => $f['product']->category_id,
            'platformId' => MarketplacePlatform::factory()->create()->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => ProductBrand::factory()->create()->id]), $f['owner']);
        $data = $this->batch($f, [], ['assignStockByDefault' => true]);
        $preview = app(ResponsibilityAssignmentService::class)->previewScopeChanges($source, $data);
        foreach ([$existing->reference, 'includes Renewed stock', 'does not separate Default Stock ownership'] as $text) {
            $this->assertStringContainsString($text, $preview);
        }
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
            $this->fail('Different Platforms do not partition physical Default Stock ownership.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('scope', $e->errors());
        }
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_stale_source_retry_returns_completed_batch_but_changed_payload_is_rejected(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $stale = $source->fresh();
        $data = $this->batch($f, ProductCategory::factory()->count(3)->create()->modelKeys());
        $action = app(ChangeResponsibilityScope::class);
        $first = $action->handleBatch($source, $data, $f['owner']);
        $this->assertSame($first->pluck('id')->all(), $action->handleBatch($stale, $data, $f['owner'])->pluck('id')->all());
        $before = $this->assignmentSnapshot();
        $changed = $this->batch($f, $data->categoryIds, ['idempotencyKey' => $data->scope->idempotencyKey, 'assignStockByDefault' => true]);
        try {
            $action->handleBatch($stale, $changed, $f['owner']);
            $this->fail('A completed request key must not accept different commercial scope values.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('differs from the completed request', $e->getMessage());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
    }

    public function test_reason_and_active_source_are_required_without_partial_changes(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $ids = ProductCategory::factory()->count(3)->create()->modelKeys();
        $before = $this->assignmentSnapshot();
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $this->batch($f, $ids, ['reason' => '   ']), $f['owner']);
            $this->fail('Reason is mandatory.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
        $source->forceFill(['status' => ResponsibilityAssignmentStatus::Inactive, 'active_fingerprint' => null, 'ended_at' => now()])->save();
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $this->batch($f, $ids), $f['owner']);
            $this->fail('Ended sources cannot be changed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assignment', $e->errors());
        }
        $this->assertSame(0, $source->successors()->count());
    }

    public static function invalidCategories(): array
    {
        return [['inactive'], ['missing'], ['duplicate'], ['too_many'], ['malformed']];
    }

    #[DataProvider('invalidCategories')]
    public function test_invalid_categories_never_end_source_or_create_partial_successors(string $type): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $ids = match ($type) {
            'inactive' => [$f['product']->category_id, ProductCategory::factory()->create(['status' => false])->id],
            'missing' => [$f['product']->category_id, 999999],
            'duplicate' => [$f['product']->category_id, (string) $f['product']->category_id],
            'too_many' => range(1, 101),
            default => ['1invalid'],
        };
        $before = $this->assignmentSnapshot();
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $this->batch($f, $ids), $f['owner']);
            $this->fail('Invalid Categories must fail closed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('category_ids', $e->errors());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
    }

    public function test_no_category_and_single_category_remain_compatible(): void
    {
        $f = $this->responsibilityFoundation();
        foreach ([[], [$f['product']->category_id]] as $ids) {
            $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => ProductBrand::factory()->create()->id]), $f['owner']);
            $data = $this->batch($f, $ids);
            $successor = app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner'])->sole();
            $this->assertSame($ids[0] ?? null, $successor->categoryScope?->product_category_id);
            $this->assertSame($data->scope->idempotencyKey, $successor->idempotency_key);
            $this->assertSame($source->id, $successor->predecessor_assignment_id);
        }
    }

    public function test_late_audit_failure_rolls_back_all_successors_and_source_but_consumes_references(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        $data = $this->batch($f, $categories);
        $before = $this->assignmentSnapshot();
        $next = DB::table('reference_sequences')->where('key', 'responsibility_assignment')->value('next_value');
        $actual = app(ReferenceSequenceService::class);
        $activity = app(ActivityLogger::class);
        $this->mock(ReferenceSequenceService::class)->shouldReceive('nextResponsibilityAssignmentReference')->times(3)
            ->andReturnUsing(function () use ($actual): string {
                $this->assertSame(1, DB::transactionLevel(), 'Only the disposable RefreshDatabase transaction may surround reference reservation.');

                return $actual->nextResponsibilityAssignmentReference();
            });
        $writes = 0;
        $this->mock(ActivityLogger::class)->shouldReceive('log')->twice()->andReturnUsing(function (...$arguments) use (&$writes, $activity) {
            if (++$writes === 2) {
                throw new RuntimeException('Simulated second audit failure');
            }

            return $activity->log(...$arguments);
        });
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
            $this->fail('A second successor failure must roll back the entire batch.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated second audit failure', $e->getMessage());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
        $this->assertSame($next + 3, DB::table('reference_sequences')->where('key', 'responsibility_assignment')->value('next_value'));
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
        $this->instance(ReferenceSequenceService::class, $actual);
        $this->instance(ActivityLogger::class, $activity);
        $retry = app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
        $this->assertCount(3, $retry);
        $this->assertTrue($retry->every(fn ($assignment) => (int) substr($assignment->reference, 3) >= $next + 3));
    }

    public function test_partial_recorded_batch_fails_safely_without_filling_missing_successors(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $categories = ProductCategory::factory()->count(3)->create()->modelKeys();
        $data = $this->batch($f, $categories);
        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, "responsibility-change:{$source->id}:{$data->scope->idempotencyKey}:category:{$categories[0]}")->toString();
        ResponsibilityAssignment::factory()->create(['predecessor_assignment_id' => $source->id, 'idempotency_key' => $key]);
        $before = $this->assignmentSnapshot();
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
            $this->fail('A partially recorded batch must not be completed silently.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('only partially recorded', $e->getMessage());
        }
        $this->assertSame($before, $this->assignmentSnapshot());
    }

    public function test_default_stock_cannot_be_enabled_for_shared_category_platform_successors(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $data = $this->batch($f, ProductCategory::factory()->count(3)->create()->modelKeys(), ['brandId' => null, 'assignStockByDefault' => true]);
        try {
            app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $f['owner']);
            $this->fail('Shared operational scopes cannot become default owners.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assign_stock_by_default', $e->errors());
            $this->assertStringContainsString('3 of 3', implode(' ', $e->errors()['scope']));
        }
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_transfer_permission_denial_and_employee_override_are_enforced_for_batch(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $data = $this->batch($f, ProductCategory::factory()->count(3)->create()->modelKeys());
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        app(EmployeePermissionOverrideService::class)->change($admin->employee, ResponsibilityPermission::Reassign->value, EmployeePermissionEffect::Deny, 'Deny reassignment', $f['owner']);
        foreach ([$f['employee']->user, $admin] as $actor) {
            try {
                app(ChangeResponsibilityScope::class)->handleBatch($source, $data, $actor);
                $this->fail('Batch must not bypass Responsibility Reassign authorization.');
            } catch (AuthorizationException) {
                $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
            }
        }
        $this->assertSame(0, $source->successors()->count());
    }

    private function batch(array $f, array $categories, array $overrides = []): ChangeResponsibilityScopeBatchData
    {
        return new ChangeResponsibilityScopeBatchData(new ChangeResponsibilityScopeData(...array_merge([
            'employeeId' => $f['employee']->id, 'brandId' => $f['brand']->id, 'productId' => null, 'categoryId' => null,
            'condition' => ProductCondition::Renewed, 'warehouseId' => null, 'platformId' => $f['platform']->id,
            'assignStockByDefault' => false, 'reason' => 'Refine Responsibility Categories', 'idempotencyKey' => (string) Str::uuid(),
        ], $overrides)), $categories);
    }

    private function formData(array $f, array $categories): array
    {
        return ['employee_id' => $f['employee']->id, 'brand_id' => $f['brand']->id, 'product_id' => null,
            'category_ids' => $categories, 'condition' => 'renewed', 'warehouse_id' => null,
            'platform_id' => $f['platform']->id, 'assign_stock_by_default' => false, 'reason' => 'Refine Categories'];
    }

    private function stockSnapshot(): array
    {
        return $this->snapshot(['product_inventories', 'inventory_allocation_balances', 'inventory_allocation_events', 'inventory_reservations', 'inventory_allocation_reservation_lines', 'stock_movements', 'stock_requests']);
    }

    private function assignmentSnapshot(): array
    {
        return $this->snapshot(['responsibility_assignments', 'responsibility_assignment_brands', 'responsibility_assignment_categories', 'responsibility_assignment_conditions', 'activity_logs']);
    }

    private function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
