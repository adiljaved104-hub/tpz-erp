<?php

namespace Tests\Feature\AccessControl;

use App\Enums\EmployeeRole;
use App\Enums\ExpensePermission;
use App\Enums\InvoicePermission;
use App\Enums\OrderPermission;
use App\Enums\ProductPermission;
use App\Enums\QuotationPermission;
use App\Enums\WebSalesPermission;
use App\Filament\Pages\Administration\AccessControl;
use App\Models\Employee;
use App\Models\Team;
use App\Services\Authorization\AccessControlModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccessControlMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_access_while_manager_and_staff_direct_routes_remain_denied(): void
    {
        foreach ([EmployeeRole::Owner, EmployeeRole::Admin] as $role) {
            $actor = $this->employee($role)->user;
            $this->actingAs($actor)->get(AccessControl::getUrl())->assertOk();
            Livewire::actingAs($actor)->test(AccessControl::class)->assertOk()->assertSee('Employee Access Control');
        }

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $actor = $this->employee($role)->user;
            $this->actingAs($actor)->get(AccessControl::getUrl())->assertForbidden();
            Livewire::actingAs($actor)->test(AccessControl::class)->assertForbidden();
        }
    }

    public function test_registry_covers_every_active_catalog_permission_once(): void
    {
        $registry = app(AccessControlModuleRegistry::class);

        $this->assertSame(['missing' => [], 'unknown' => [], 'duplicates' => []], $registry->reconcile());
        $this->assertSame(count($registry->managedPermissionKeys()), count(array_unique($registry->managedPermissionKeys())));
    }

    public function test_all_required_current_modules_render_in_grouped_cards(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);

        Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->call('selectEmployee', $staff->id)
            ->assertSee('People & HR')
            ->assertSee('Products & Inventory')
            ->assertSee('Web Sales')
            ->assertSee('Quotations & Invoices')
            ->assertSee('Tax Invoices')
            ->assertSee('Quotations / Proforma')
            ->assertSee('Business Expenses (AED)')
            ->assertSee('Pakistan Office Finance (PKR)')
            ->assertSee('Backup Settings')
            ->assertSee('Notification Rules')
            ->assertSee('Reports & Exports');
    }

    public function test_module_view_and_edit_is_staged_until_explicit_save_and_maps_only_primary_permissions(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->call('selectEmployee', $staff->id)
            ->call('setModuleAccess', 'products', 'view_edit')
            ->assertSee('Save Changes');

        $this->assertDatabaseCount('employee_permission_overrides', 0);
        $this->assertSame('allow', $component->instance()->draftSettings[ProductPermission::View->value]);
        $this->assertSame('allow', $component->instance()->draftSettings[ProductPermission::Create->value]);
        $this->assertSame('allow', $component->instance()->draftSettings[ProductPermission::Update->value]);
        $this->assertSame('inherit', $component->instance()->draftSettings[ProductPermission::ViewCostPrice->value]);

        $component->call('saveChanges');
        foreach ([ProductPermission::View, ProductPermission::Create, ProductPermission::Update] as $permission) {
            $this->assertDatabaseHas('employee_permission_overrides', [
                'employee_id' => $staff->id,
                'permission_key' => $permission->value,
                'effect' => 'allow',
            ]);
        }
        $this->assertDatabaseMissing('employee_permission_overrides', [
            'employee_id' => $staff->id,
            'permission_key' => ProductPermission::ViewCostPrice->value,
        ]);
    }

    public function test_none_view_and_role_default_mapping_preserves_advanced_overrides(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)->call('selectEmployee', $staff->id);

        $component->call('stagePermission', OrderPermission::Export->value, 'allow')
            ->call('setModuleAccess', 'orders', 'none');
        $this->assertSame('allow', $component->instance()->draftSettings[OrderPermission::Export->value]);
        $this->assertSame('deny', $component->instance()->draftSettings[OrderPermission::View->value]);
        $this->assertSame('deny', $component->instance()->draftSettings[OrderPermission::Create->value]);

        $component->call('setModuleAccess', 'orders', 'view');
        $this->assertSame('allow', $component->instance()->draftSettings[OrderPermission::View->value]);
        $this->assertSame('deny', $component->instance()->draftSettings[OrderPermission::Create->value]);

        $component->call('inheritModule', 'orders');
        $this->assertSame('inherit', $component->instance()->draftSettings[OrderPermission::View->value]);
        $this->assertSame('allow', $component->instance()->draftSettings[OrderPermission::Export->value]);
    }

    public function test_financial_permissions_remain_advanced_and_require_owner_reason(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->call('selectEmployee', $staff->id)
            ->call('setModuleAccess', 'web_sales', 'view_edit');

        $this->assertSame('inherit', $component->instance()->draftSettings[WebSalesPermission::ViewCost->value]);
        $this->assertSame('inherit', $component->instance()->draftSettings[WebSalesPermission::ViewGrossProfit->value]);

        $component->call('stagePermission', WebSalesPermission::ViewCost->value, 'allow')
            ->call('saveChanges')
            ->assertHasErrors(['changeReason']);
        $this->assertDatabaseMissing('employee_permission_overrides', ['employee_id' => $staff->id]);

        $component->set('changeReason', 'Approved finance visibility')->call('saveChanges');
        $this->assertDatabaseHas('employee_permission_overrides', [
            'employee_id' => $staff->id,
            'permission_key' => WebSalesPermission::ViewCost->value,
            'effect' => 'allow',
        ]);
    }

    public function test_admin_can_manage_operational_but_not_financial_permissions(): void
    {
        $admin = $this->employee(EmployeeRole::Admin);
        $staff = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($admin->user)->test(AccessControl::class)
            ->call('selectEmployee', $staff->id)
            ->call('setModuleAccess', 'quotations', 'view_edit')
            ->call('saveChanges');

        $this->assertDatabaseHas('employee_permission_overrides', [
            'employee_id' => $staff->id,
            'permission_key' => QuotationPermission::Create->value,
            'effect' => 'allow',
        ]);

        $component->call('stagePermission', ExpensePermission::ViewAmount->value, 'allow')->assertForbidden();
    }

    public function test_owner_target_is_protected_from_ui_and_tampered_livewire_calls(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $admin = $this->employee(EmployeeRole::Admin);
        $component = Livewire::actingAs($admin->user)->test(AccessControl::class)
            ->call('selectEmployee', $owner->id)
            ->assertSee('Owner Protected')
            ->assertSee('Access protected');

        $component->call('stagePermission', InvoicePermission::View->value, 'deny')->assertForbidden();
    }

    public function test_group_bulk_stages_primary_permissions_but_not_advanced_financial_keys(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $staff = $this->employee(EmployeeRole::Staff);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->call('selectEmployee', $staff->id)
            ->call('setGroupAccess', 'finance', 'view_edit');

        $this->assertSame('allow', $component->instance()->draftSettings[ExpensePermission::View->value]);
        $this->assertSame('allow', $component->instance()->draftSettings[ExpensePermission::Create->value]);
        $this->assertSame('inherit', $component->instance()->draftSettings[ExpensePermission::ViewAmount->value]);
        $this->assertDatabaseCount('employee_permission_overrides', 0);
    }

    public function test_employee_and_module_search_filters_are_live_and_responsive_layout_has_no_matrix(): void
    {
        $owner = $this->employee(EmployeeRole::Owner);
        $team = Team::query()->create(['name' => 'E-commerce', 'description' => null, 'status' => true]);
        $staff = $this->employee(EmployeeRole::Staff, ['name' => 'Asif Hameed', 'team_id' => $team->id]);
        $other = $this->employee(EmployeeRole::Manager, ['name' => 'Other Manager']);
        $component = Livewire::actingAs($owner->user)->test(AccessControl::class)
            ->set('search', 'Asif')
            ->assertSee($staff->name)
            ->assertDontSee($other->name)
            ->call('selectEmployee', $staff->id)
            ->set('moduleSearch', 'quotation')
            ->assertSee('Quotations / Proforma')
            ->assertDontSee('Business Expenses (AED)');

        $html = $component->html();
        $this->assertStringContainsString('ac-workspace', $html);
        $this->assertStringContainsString('ac-employee-trigger', $html);
        $this->assertStringContainsString('ac-employee-drawer-backdrop', $html);
        $this->assertStringContainsString('ac-employee-row', $html);
        $this->assertStringContainsString('ac-permission-toolbar', $html);
        $this->assertStringContainsString('ac-module-card', $html);
        $this->assertStringContainsString('ac-segmented', $html);
        $this->assertStringContainsString('Changed Only', $html);
        $this->assertStringContainsString('Default role access is used unless you set a custom permission.', $html);
        $this->assertStringContainsString('Using Role Default', $html);
        $this->assertStringContainsString('Reset to Role Default', $html);
        $this->assertStringContainsString('Allow for Employee', $html);
        $this->assertStringContainsString('Block for Employee', $html);
        $this->assertStringNotContainsString('Protected for this employee or actor.', $html);
        $this->assertStringContainsString('fi-input-wrp', $html);
        $this->assertStringContainsString('min-w-0', $html);
        $this->assertStringNotContainsString('min-w-max table-fixed', $html);
        $this->assertStringNotContainsString('permission-column-', $html);

        $stylesheet = file_get_contents(resource_path('css/filament/access-control.css'));
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("[data-testid='module-access-control']", $stylesheet);
        $this->assertStringContainsString('@media (min-width: 768px)', $stylesheet);
        $this->assertStringContainsString('@media (min-width: 1280px)', $stylesheet);
        $this->assertStringContainsString('@media (min-width: 1400px)', $stylesheet);
        $this->assertStringContainsString('@media (max-width: 639px)', $stylesheet);
    }

    public function test_tax_invoice_and_quotation_granular_actions_are_reconciled_without_inventing_update_permission(): void
    {
        $registry = app(AccessControlModuleRegistry::class);
        $modules = $registry->keyed();
        $invoice = $modules['tax_invoices'];
        $quotation = $modules['quotations'];

        $this->assertContains(InvoicePermission::Create->value, $invoice['edit_keys']);
        $this->assertContains(InvoicePermission::ViewAll->value, $invoice['advanced_keys']);
        $this->assertContains(InvoicePermission::Void->value, $invoice['advanced_keys']);
        $this->assertContains(InvoicePermission::Export->value, $invoice['advanced_keys']);
        $this->assertContains(QuotationPermission::ConvertOrder->value, $quotation['advanced_keys']);
        $this->assertContains(QuotationPermission::ConvertInvoice->value, $quotation['advanced_keys']);
        $this->assertContains(QuotationPermission::Cancel->value, $quotation['advanced_keys']);
        $this->assertNotContains('invoice.update', $registry->managedPermissionKeys());
    }

    /** @param array<string, mixed> $attributes */
    private function employee(EmployeeRole $role, array $attributes = []): Employee
    {
        $employee = Employee::factory()->role($role)->create($attributes);
        $email = $role->value.'-'.str()->random(10).'@techpointzone.com';
        $employee->user()->update(['email' => $email]);
        $employee->update(['email' => $email]);

        return $employee->refresh()->load('user');
    }
}
