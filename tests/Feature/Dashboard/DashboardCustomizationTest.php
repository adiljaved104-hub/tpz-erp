<?php

namespace Tests\Feature\Dashboard;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Filament\Widgets\ErpDashboardOverview;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Models\UserDashboardPreference;
use App\Services\Dashboard\DashboardPreferenceService;
use App\Services\Dashboard\DashboardWidgetRegistry;
use App\Services\Dashboard\ErpDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_login_uses_role_defaults_without_creating_a_preference_row(): void
    {
        $registry = app(DashboardWidgetRegistry::class);

        foreach ([
            [EmployeeRole::Owner, ['sales', 'inventory', 'inventory_intelligence', 'work', 'attention', 'service', 'hr', 'responsibilities']],
            [EmployeeRole::Admin, ['sales', 'work', 'inventory', 'attention', 'service', 'inventory_intelligence', 'hr', 'responsibilities']],
            [EmployeeRole::Manager, ['work', 'attention', 'sales', 'inventory', 'service', 'hr', 'inventory_intelligence', 'responsibilities']],
            [EmployeeRole::Staff, ['work', 'attention', 'sales', 'inventory', 'responsibilities', 'hr', 'inventory_intelligence']],
        ] as [$role, $expected]) {
            $user = $this->user($role, $role->value);
            $this->assertSame($expected, $registry->defaultLayout($user));
            $this->assertSame($expected, app(DashboardPreferenceService::class)->resolve($user)['visible']);
        }

        $this->assertDatabaseCount('user_dashboard_preferences', 0);
    }

    public function test_each_user_has_independent_order_visibility_and_reset(): void
    {
        $first = $this->user(EmployeeRole::Owner, 'First Owner');
        $second = $this->user(EmployeeRole::Owner, 'Second Owner');
        $service = app(DashboardPreferenceService::class);
        $firstDefault = app(DashboardWidgetRegistry::class)->defaultLayout($first);
        $custom = array_values(array_reverse($firstDefault));

        $service->save($first, DashboardWidgetRegistry::DASHBOARD_KEY, $custom, ['inventory_intelligence']);

        $this->assertSame($custom, $service->resolve($first)['layout']);
        $this->assertSame(['inventory_intelligence'], $service->resolve($first)['hidden']);
        $this->assertSame($firstDefault, $service->resolve($second)['layout']);
        $this->assertSame([], $service->resolve($second)['hidden']);
        $this->assertDatabaseCount('user_dashboard_preferences', 1);

        $service->reset($first);

        $this->assertSame($firstDefault, $service->resolve($first)['layout']);
        $this->assertFalse($service->resolve($first)['has_override']);
        $this->assertDatabaseCount('user_dashboard_preferences', 0);
    }

    public function test_livewire_reorder_hide_show_and_reload_persist_personal_preference(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $default = app(DashboardWidgetRegistry::class)->defaultLayout($owner);
        $reordered = array_values(array_reverse($default));

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->assertSee('Customize Dashboard')
            ->call('toggleCustomizeMode')
            ->assertSet('customizeMode', true)
            ->assertSee('Reset Dashboard')
            ->assertSeeHtml('data-dashboard-customizer')
            ->assertSeeHtml('draggable="true"')
            ->call('reorderWidgets', $reordered)
            ->assertSet('widgetOrder', $reordered)
            ->call('toggleWidget', 'inventory_intelligence')
            ->assertSet('hiddenWidgets', ['inventory_intelligence'])
            ->assertDontSeeHtml('data-dashboard-widget="inventory_intelligence"');

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->assertSet('widgetOrder', $reordered)
            ->assertSet('hiddenWidgets', ['inventory_intelligence'])
            ->call('toggleWidget', 'inventory_intelligence')
            ->assertSet('hiddenWidgets', [])
            ->assertSeeHtml('data-dashboard-widget="inventory_intelligence"');

        $this->assertDatabaseHas('user_dashboard_preferences', [
            'user_id' => $owner->id,
            'dashboard_key' => DashboardWidgetRegistry::DASHBOARD_KEY,
        ]);
    }

    public function test_crafted_unknown_duplicate_invalid_dashboard_and_unauthorized_keys_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $service = app(DashboardPreferenceService::class);
        $layout = app(DashboardWidgetRegistry::class)->defaultLayout($owner);

        $this->assertValidationFailure(fn () => $service->save($owner, 'other', $layout, []), 'dashboard_key');
        $this->assertValidationFailure(fn () => $service->save($owner, 'erp', ['sales', 'sales'], []), 'layout');
        $this->assertValidationFailure(fn () => $service->save($owner, 'erp', ['unknown-widget'], []), 'layout');

        $this->denyOrderView($owner, $admin);
        $this->assertNotContains('sales', app(DashboardWidgetRegistry::class)->defaultLayout($admin));
        $this->assertValidationFailure(fn () => $service->save($admin, 'erp', ['sales'], []), 'layout');
        $this->assertDatabaseMissing('user_dashboard_preferences', ['user_id' => $admin->id]);
    }

    public function test_permission_changes_filter_saved_preferences_without_mutating_or_granting_access(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        $admin = $this->user(EmployeeRole::Admin, 'Admin');
        $service = app(DashboardPreferenceService::class);
        $layout = app(DashboardWidgetRegistry::class)->defaultLayout($admin);
        $service->save($admin, 'erp', $layout, []);

        $this->denyOrderView($owner, $admin);
        $this->assertNotContains('sales', $service->resolve($admin)['visible']);
        Livewire::actingAs($admin)->test(ErpDashboardOverview::class)
            ->assertDontSeeHtml('data-dashboard-widget="sales"')
            ->call('toggleCustomizeMode')
            ->assertDontSee('Sales &amp; Orders', false);

        EmployeePermissionOverride::query()
            ->where('employee_id', $admin->employee->id)
            ->where('permission_key', OrderPermission::View->value)
            ->delete();
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($admin->employee->id);

        $this->assertContains('sales', $service->resolve($admin->refresh())['visible']);
        $this->assertContains('sales', UserDashboardPreference::query()->where('user_id', $admin->id)->firstOrFail()->layout);
    }

    public function test_hidden_sections_are_resolved_before_expensive_inventory_and_order_queries(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = app(ErpDashboardService::class)->forUser(
            $owner,
            widgetKeys: ['work'],
        );
        $queries = collect(DB::getQueryLog())->pluck('query')->map('strtolower');
        DB::disableQueryLog();

        $this->assertTrue($data['cards']->every(fn (array $card): bool => in_array($card['key'], ['tasks', 'chat_unread', 'notifications'], true)));
        $diagnostic = $queries->implode("\n");
        $this->assertFalse($queries->contains(fn (string $query): bool => str_contains($query, 'product_inventories')), $diagnostic);
        $this->assertFalse($queries->contains(fn (string $query): bool => str_contains($query, 'order_date')), $diagnostic);
        $this->assertFalse($queries->contains(fn (string $query): bool => str_contains($query, 'average_cost')), $diagnostic);
    }

    public function test_customization_markup_is_responsive_and_keeps_touch_order_controls(): void
    {
        $owner = $this->user(EmployeeRole::Owner, 'Owner');

        Livewire::actingAs($owner)->test(ErpDashboardOverview::class)
            ->call('toggleCustomizeMode')
            ->assertSeeHtml('@media (max-width: 47.999rem)')
            ->assertSeeHtml('.erp-dashboard-drag-handle { display: none; }')
            ->assertSeeHtml('grid-template-columns: repeat(2, minmax(0, 1fr))')
            ->assertSeeHtml('grid-template-columns: repeat(3, minmax(0, 1fr))')
            ->assertSeeHtml('grid-template-columns: repeat(4, minmax(0, 1fr))')
            ->assertSee('Move Sales &amp; Orders earlier', false)
            ->assertSee('Move Sales &amp; Orders later', false);
    }

    private function denyOrderView(User $actor, User $target): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $target->employee->id,
            'permission_key' => OrderPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $actor->id,
            'reason' => 'Dashboard customization authorization regression',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($target->employee->id);
    }

    private function assertValidationFailure(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected Dashboard preference validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function user(EmployeeRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        Employee::factory()->for($user)->role($role)->create([
            'name' => $name,
            'email' => $user->email,
            'status' => true,
        ]);

        return $user->refresh();
    }
}
