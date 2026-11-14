<?php

namespace Tests\Feature\UiUx;

use App\Enums\EmployeeRole;
use App\Filament\Pages\UxLab;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UxLabPrototypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ux_lab_is_owner_only_and_renders_the_read_only_controls(): void
    {
        $this->withoutVite(); // Design CSS lives inside the isolated UX Lab Blade view.
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;

        $this->actingAs($owner)->get(UxLab::getUrl())->assertOk()->assertSee('TPZ ERP / CONCEPT STUDIO')->assertSee('Reset');
        $this->actingAs($staff)->get(UxLab::getUrl())->assertForbidden();
    }

    public function test_owner_can_preview_both_designs_and_complete_simulated_order_without_writes(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);

        Livewire::test(UxLab::class)
            ->assertSet('direction', 'aurora')
            ->set('direction', 'executive')
            ->assertSee('Executive')
            ->set('viewport', 'mobile')
            ->call('chooseWorkspace', 'sales')
            ->call('chooseScreen', 'orders')
            ->call('openRecord', 'SO-DEMO-001')
            ->assertSet('drawerOpen', true)
            ->call('closeDrawer')
            ->call('openCreateOrder')
            ->call('addDemoProduct', 'TPZ-DEMO-001')
            ->assertSet('demoOrderLines.0.qty', 1)
            ->call('updateDemoQuantity', 0, 3)
            ->assertSet('demoOrderLines.0.qty', 3)
            ->call('advanceOrderFlow')
            ->call('advanceOrderFlow')
            ->call('advanceOrderFlow')
            ->assertSet('flowStep', 'confirmed')
            ->assertSee('no operational transaction was written');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_requests', 0);
    }

    public function test_owner_can_filter_demo_data_and_remove_product_lines(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);

        Livewire::test(UxLab::class)
            ->set('search', 'NO-RESULT-XYZ')
            ->assertDontSee('SO-DEMO-001 · Noor Electronics')
            ->set('search', '')
            ->call('openCreateOrder')
            ->call('addDemoProduct', 'TPZ-DEMO-002')
            ->call('removeDemoProduct', 0)
            ->assertSet('flowStep', 'catalog')
            ->assertSet('demoOrderLines', []);
    }

    public function test_contextual_tabs_show_only_relevant_simulated_records(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $this->actingAs($owner);

        Livewire::test(UxLab::class)
            ->call('chooseWorkspace', 'sales')
            ->call('chooseScreen', 'web')
            ->assertSee('WS-DEMO-014')
            ->assertDontSee('SO-DEMO-001 · Noor Electronics')
            ->call('chooseScreen', 'orders')
            ->assertSee('SO-DEMO-001 · Noor Electronics')
            ->assertDontSee('WS-DEMO-014')
            ->call('chooseWorkspace', 'inventory')
            ->call('chooseScreen', 'adjustments')
            ->assertSee('ADJ-DEMO-002')
            ->assertDontSee('ST-DEMO-004 · Warehouse receiving');
    }

    public function test_prototype_contains_no_operational_write_controls(): void
    {
        $view = file_get_contents(resource_path('views/filament/pages/ux-lab.blade.php'));

        $this->assertStringContainsString('Simulation live', $view);
        $this->assertStringContainsString('Create Sales Order', $view);
        $this->assertStringContainsString('openRecord', $view);
        $this->assertStringContainsString('addDemoProduct', $view);
        $this->assertStringContainsString('no operational transaction was written', $view);
        $this->assertStringNotContainsString('Save & Reserve', $view);
        $this->assertStringNotContainsString('Mark Shipped', $view);
        $this->assertStringContainsString('downloadFeedback', $view);
        $this->assertStringContainsString('tpz-lab-shell', $view);
        $this->assertStringContainsString('wire:model.live="viewport"', $view);
    }
}
