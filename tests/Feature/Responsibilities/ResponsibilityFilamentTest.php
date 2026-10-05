<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Filament\Pages\Inventory\MyInventory;
use App\Filament\Pages\Inventory\ResponsibilityReports;
use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment as CreateResponsibilityAssignmentPage;
use App\Filament\Resources\ResponsibilityAssignments\Pages\ListResponsibilityAssignments;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use App\Models\InventoryResponsibilityQuantity;
use App\Models\MarketplacePlatform;
use App\Models\ProductBrand;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentWarehouse;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class ResponsibilityFilamentTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_stock_default_action_requires_reason_reports_ineligible_scope_and_confirms_success(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = $this->assignment($f['owner'], $f['employee']->id);
        ResponsibilityAssignmentWarehouse::query()->create(['assignment_id' => $assignment->id, 'warehouse_id' => $f['inventory']->warehouse_id]);
        $this->actingAs($f['owner']);
        Livewire::test(ListResponsibilityAssignments::class)
            ->callTableAction('setStockDefault', $assignment, ['enabled' => true, 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ListResponsibilityAssignments::class)
            ->callTableAction('setStockDefault', $assignment, ['enabled' => true, 'reason' => 'Future stock'])
            ->assertHasTableActionErrors(['enabled'])
            ->assertNotified('Default stock assignment was not changed');
        $this->assertFalse($assignment->refresh()->assign_stock_by_default);

        $valid = $this->assignment($f['owner'], $f['employee']->id);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $valid->id, 'product_brand_id' => $f['brand']->id]);
        Livewire::test(ListResponsibilityAssignments::class)
            ->callTableAction('setStockDefault', $valid, ['enabled' => true, 'reason' => 'Future stock'])
            ->assertHasNoTableActionErrors()->assertNotified('Default stock assignment updated');
        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $valid->refresh()->status);
        $this->assertTrue($valid->successors()->firstOrFail()->assign_stock_by_default);
    }

    public function test_scope_change_action_is_visible_to_authorized_management(): void
    {
        $f = $this->responsibilityFoundation();
        $assignment = $this->assignment($f['owner'], $f['employee']->id);

        $this->actingAs($f['owner']);
        Livewire::test(ListResponsibilityAssignments::class)
            ->callTableAction('changeScope', $assignment, [
                'employee_id' => $assignment->employee_id,
                'brand_id' => $f['brand']->id,
                'product_id' => null,
                'category_ids' => [],
                'condition' => null,
                'warehouse_id' => null,
                'platform_id' => $f['platform']->id,
                'assign_stock_by_default' => false,
                'reason' => 'Add marketplace platform scope',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Responsibility changed');

        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $assignment->refresh()->status);
        $this->assertSame($f['platform']->id, $assignment->successors()->firstOrFail()->platformScope->marketplace_platform_id);
    }

    public function test_change_scope_modal_accepts_brand_category_condition_and_platform(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::New, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $this->actingAs($f['owner']);
        Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $this->changeFormData($f))
            ->assertHasNoTableActionErrors()->assertNotified('Responsibility changed');
        $this->assertSame(ResponsibilityAssignmentStatus::Superseded, $source->refresh()->status);
        $this->assertSame(ProductCondition::Renewed, $source->successors()->sole()->conditionScope->product_condition);
    }

    public function test_invalid_scope_stays_mounted_and_preserves_entered_values(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        $data = $this->changeFormData($f);
        foreach (['brand_id', 'condition', 'platform_id'] as $field) {
            $data[$field] = null;
        }
        $data['category_ids'] = [];
        $data['assign_stock_by_default'] = false;
        $this->actingAs($f['owner']);
        $component = Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $data)
            ->assertHasTableActionErrors(['scope'])->assertActionMounted(TestAction::make('changeScope')->table($source))
            ->assertTableActionDataSet(['reason' => $data['reason'], 'employee_id' => $data['employee_id']])
            ->assertNotified('Responsibility was not changed');
        $this->assertStringContainsString('Select at least one Product, Brand, Category, Condition, Warehouse, or Platform.', implode(' ', $component->instance()->getErrorBag()->all()));
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
        $this->assertDatabaseMissing('activity_logs', ['event' => 'responsibility.scope_changed']);
    }

    public function test_exact_same_employee_duplicate_is_modal_validation_not_a_page_error(): void
    {
        $f = $this->responsibilityFoundation();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f), $f['owner']);
        app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'categoryId' => $f['product']->category_id, 'condition' => ProductCondition::Renewed, 'platformId' => $f['platform']->id,
        ]), $f['owner']);
        $this->actingAs($f['owner']);
        $data = $this->changeFormData($f);
        $component = Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $data)
            ->assertHasTableActionErrors(['scope'])->assertActionMounted(TestAction::make('changeScope')->table($source))
            ->assertTableActionDataSet(['reason' => $data['reason'], 'condition' => 'renewed'])
            ->assertNotified('Responsibility was not changed');
        $this->assertStringContainsString('This exact active responsibility combination already exists.', implode(' ', $component->instance()->getErrorBag()->all()));
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
    }

    public function test_operational_and_physical_default_conflicts_keep_the_modal_open(): void
    {
        $f = $this->responsibilityFoundation();
        $sourceBrand = ProductBrand::factory()->create();
        $source = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: ['brandId' => $sourceBrand->id]), $f['owner']);
        $other = $this->responsibilityUser(EmployeeRole::Staff);
        $existing = app(CreateResponsibilityAssignment::class)->handle($this->assignmentData($f, overrides: [
            'employeeId' => $other->employee->id, 'categoryId' => $f['product']->category_id,
            'condition' => ProductCondition::Renewed, 'platformId' => $f['platform']->id, 'assignStockByDefault' => true,
        ]), $f['owner']);
        $this->actingAs($f['owner']);
        $data = $this->changeFormData($f);
        $component = Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $data)
            ->assertHasTableActionErrors(['scope'])->assertActionMounted(TestAction::make('changeScope')->table($source))
            ->assertNotified('Responsibility was not changed');
        $this->assertStringContainsString($other->employee->name, implode(' ', $component->instance()->getErrorBag()->all()));
        $data['platform_id'] = MarketplacePlatform::factory()->create()->id;
        $component = Livewire::test(ListResponsibilityAssignments::class)->callTableAction('changeScope', $source, $data)
            ->assertHasTableActionErrors(['scope'])->assertActionMounted(TestAction::make('changeScope')->table($source))
            ->assertNotified('Responsibility was not changed');
        $this->assertStringContainsString('default stock holder', implode(' ', $component->instance()->getErrorBag()->all()));
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $source->refresh()->status);
        $this->assertSame(0, $source->successors()->count());
        $this->assertSame(ResponsibilityAssignmentStatus::Active, $existing->refresh()->status);
    }

    private function changeFormData(array $f): array
    {
        return [
            'employee_id' => $f['employee']->id, 'brand_id' => $f['brand']->id, 'product_id' => null,
            'category_ids' => [$f['product']->category_id], 'condition' => 'renewed', 'warehouse_id' => null,
            'platform_id' => $f['platform']->id, 'assign_stock_by_default' => true, 'reason' => 'New to Renewed',
        ];
    }

    public function test_direct_page_access_and_navigation_follow_server_side_permissions(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        $this->actingAs($owner);
        $this->assertTrue(ResponsibilityAssignmentResource::canViewAny());
        $this->assertTrue(MarketplacePlatformResource::canViewAny());
        $this->assertTrue(MyInventory::canAccess());
        $this->assertTrue(ResponsibilityReports::canAccess());

        $this->actingAs($staff);
        $this->assertTrue(MyInventory::canAccess());
        $this->assertFalse(MarketplacePlatformResource::canViewAny());
        $this->assertFalse(ResponsibilityReports::canAccess());
        $this->get('/admin/responsibility-reports')->assertForbidden();
        $this->get('/admin/responsibility-assignments/create')->assertForbidden();
        Livewire::test(CreateResponsibilityAssignmentPage::class)->assertForbidden();
    }

    public function test_list_tabs_use_authorization_scoped_counts(): void
    {
        $f = $this->responsibilityFoundation();
        $other = $this->responsibilityUser(EmployeeRole::Staff);

        $this->assignment($f['owner'], $f['employee']->id);
        $this->assignment($f['owner'], $f['employee']->id, ResponsibilityAssignmentStatus::Inactive);
        $this->assignment($f['owner'], $other->employee->id);

        $this->actingAs($f['owner']);
        $ownerTabs = Livewire::test(ListResponsibilityAssignments::class)
            ->assertOk()
            ->instance()
            ->getTabs();
        $this->assertSame('2', $ownerTabs['active']->getBadge());
        $this->assertSame('1', $ownerTabs['inactive']->getBadge());
        $this->assertSame('3', $ownerTabs['all']->getBadge());

        $this->actingAs($f['employee']->user);
        $staffTabs = Livewire::test(ListResponsibilityAssignments::class)
            ->assertOk()
            ->instance()
            ->getTabs();
        $this->assertSame('1', $staffTabs['active']->getBadge());
        $this->assertSame('1', $staffTabs['inactive']->getBadge());
        $this->assertSame('2', $staffTabs['all']->getBadge());
    }

    public function test_list_query_preserves_quantity_computed_columns(): void
    {
        $f = $this->responsibilityFoundation(10, 2);
        $assignment = $this->assignment($f['owner'], $f['employee']->id, mode: ResponsibilityAssignmentMode::Quantity);
        InventoryResponsibilityQuantity::query()->create([
            'assignment_id' => $assignment->id,
            'product_inventory_id' => $f['inventory']->id,
            'assigned_quantity' => 4,
        ]);

        $this->actingAs($f['owner']);
        $row = ResponsibilityAssignmentResource::getEloquentQuery()->findOrFail($assignment->id);

        $this->assertSame(8, (int) $row->physical_sellable);
        $this->assertSame(4, (int) $row->aggregate_assigned);
        Livewire::test(ListResponsibilityAssignments::class)->assertOk()->assertCanSeeTableRecords([$assignment]);
    }

    private function assignment(User $owner, int $employeeId, ResponsibilityAssignmentStatus $status = ResponsibilityAssignmentStatus::Active, ResponsibilityAssignmentMode $mode = ResponsibilityAssignmentMode::Scope): ResponsibilityAssignment
    {
        return ResponsibilityAssignment::factory()->create([
            'employee_id' => $employeeId,
            'assigned_by_user_id' => $owner->id,
            'assignment_mode' => $mode,
            'status' => $status,
            'active_fingerprint' => $status === ResponsibilityAssignmentStatus::Active ? hash('sha256', fake()->uuid()) : null,
            'ended_at' => $status === ResponsibilityAssignmentStatus::Active ? null : now(),
            'ended_by_user_id' => $status === ResponsibilityAssignmentStatus::Active ? null : $owner->id,
        ]);
    }
}
