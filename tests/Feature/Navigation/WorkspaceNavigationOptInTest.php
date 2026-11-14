<?php

namespace Tests\Feature\Navigation;

use App\Enums\EmployeeRole;
use App\Filament\Pages\CustomizeNavigation;
use App\Models\Employee;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceNavigationOptInTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_opt_into_simplified_workspace_navigation_without_changing_operational_permissions(): void
    {
        $this->withoutVite();
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CustomizeNavigation::class)
            ->assertSee('Simplified workspace navigation')
            ->call('toggleWorkspaceMode');

        $this->assertTrue(app(NavigationPreferenceService::class)->workspaceModeEnabled($owner));
        $this->assertSame([], app(UserUiPreferenceService::class)->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
    }

    public function test_workspace_mode_uses_canonical_groups_and_keeps_all_original_destinations(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $service = app(NavigationPreferenceService::class);
        $groups = [
            NavigationGroup::make('Accounting')->items([NavigationItem::make('General Ledger')->url('/admin/accounting/general-ledger')]),
            NavigationGroup::make('Purchasing')->items([
                NavigationItem::make('Purchases')->url('/admin/purchases'),
                NavigationItem::make('Products')->url('/admin/products'),
            ]),
            NavigationGroup::make('Marketplace')->items([NavigationItem::make('Marketplace Accounts')->url('/admin/marketplace-accounts')]),
            NavigationGroup::make('Workspace')->items([NavigationItem::make('My Work')->url('/admin/my-work')]),
            NavigationGroup::make('Sales')->items([NavigationItem::make('Sales Orders')->url('/admin/orders')]),
            NavigationGroup::make('Inventory')->items([NavigationItem::make('Stock Requests')->url('/admin/stock-requests')]),
            NavigationGroup::make('Returns & Service')->items([NavigationItem::make('Customer Returns')->url('/admin/customer-returns')]),
            NavigationGroup::make('Finance')->items([NavigationItem::make('Expenses')->url('/admin/expenses')]),
            NavigationGroup::make('People')->items([NavigationItem::make('Employees')->url('/admin/employees')]),
            NavigationGroup::make('Administration')->items([NavigationItem::make('Access Control')->url('/admin/access-control')]),
        ];
        $original = $service->apply($owner, $groups);
        $this->assertSame('Accounting', $original[0]->getLabel());

        $service->setWorkspaceMode($owner, true);
        $reorganized = $service->apply($owner, $groups);
        $this->assertSame([
            'Home', 'Sales', 'Inventory & Warehouse', 'Purchasing', 'Returns & QC',
            'Finance & Accounting', 'Team & HR', 'Administration',
        ], array_map(fn (NavigationGroup $group): ?string => $group->getLabel(), $reorganized));

        $urls = fn (array $nav): array => collect($nav)->flatMap(
            fn (NavigationGroup $group): array => collect($group->getItems())
                ->map(fn (NavigationItem $item): ?string => $item->getUrl())->all(),
        )->all();
        $this->assertEqualsCanonicalizing($urls($original), $urls($reorganized));
        $this->assertContains('/admin/products', collect($reorganized[2]->getItems())
            ->map(fn (NavigationItem $item): ?string => $item->getUrl())->all());
        $this->assertNotContains('/admin/products', collect($reorganized[3]->getItems())
            ->map(fn (NavigationItem $item): ?string => $item->getUrl())->all());

        $service->setWorkspaceMode($owner, false);
        $restored = $service->apply($owner, $groups);
        $this->assertSame(
            array_map(fn (NavigationGroup $group): ?string => $group->getLabel(), $original),
            array_map(fn (NavigationGroup $group): ?string => $group->getLabel(), $restored),
        );
    }

    public function test_workspace_hidden_items_and_order_do_not_overwrite_legacy_preferences(): void
    {
        $owner = Employee::factory()->role(EmployeeRole::Owner)->create()->user;
        $preferences = app(UserUiPreferenceService::class);
        $groups = [NavigationGroup::make('Purchasing')->items([
            NavigationItem::make('Purchases')->url('/admin/purchases'),
            NavigationItem::make('Products')->url('/admin/products'),
        ])];
        $service = new class($preferences, $groups) extends NavigationPreferenceService
        {
            public function __construct(UserUiPreferenceService $preferences, private readonly array $groups)
            {
                parent::__construct($preferences);
            }

            public function authorizedNavigation(): array
            {
                return $this->groups;
            }
        };

        $legacyItemKey = 'item:'.hash('sha256', 'Purchasing||Products|admin/products');
        $legacyOrderKey = 'group:'.hash('sha256', 'Purchasing');
        $preferences->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$legacyItemKey]);
        $preferences->put($owner, UserUiPreferenceService::NAVIGATION_GROUP_ORDER, [$legacyOrderKey]);

        $service->setWorkspaceMode($owner, true);
        $groupsForOwner = $service->customizerGroups($owner);
        $inventory = collect($groupsForOwner)->firstWhere('label', 'Inventory & Warehouse');
        $this->assertNotNull($inventory);
        $product = collect($inventory['items'])->firstWhere('label', 'Products');
        $this->assertTrue($product['hidden']);

        $service->saveHiddenItems($owner, [$product['key']]);
        $service->saveGroupOrder($owner, array_column($groupsForOwner, 'key'));
        $this->assertSame([$legacyItemKey], $preferences->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
        $this->assertSame([$legacyOrderKey], $preferences->get($owner, UserUiPreferenceService::NAVIGATION_GROUP_ORDER));
        $this->assertSame([$product['key']], $preferences->get($owner, UserUiPreferenceService::NAVIGATION_WORKSPACE_HIDDEN_ITEMS));

        $service->setWorkspaceMode($owner, false);
        $this->assertSame('Purchasing', $service->customizerGroups($owner)[0]['label']);
        $this->assertTrue(collect($service->customizerGroups($owner)[0]['items'])->firstWhere('label', 'Products')['hidden']);
    }

    public function test_staff_cannot_enable_owner_navigation_even_if_preference_is_manually_written(): void
    {
        $this->withoutVite();
        $staff = Employee::factory()->role(EmployeeRole::Staff)->create()->user;
        $service = app(NavigationPreferenceService::class);

        app(UserUiPreferenceService::class)->put($staff, UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE, ['enabled']);
        $this->assertFalse($service->workspaceModeEnabled($staff));
        $this->actingAs($staff);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(CustomizeNavigation::class)->assertDontSee('Simplified workspace navigation');
    }

    public function test_workspace_mode_preference_is_not_a_new_access_control_permission(): void
    {
        $this->assertSame('navigation.workspace_mode', UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE);
    }
}
