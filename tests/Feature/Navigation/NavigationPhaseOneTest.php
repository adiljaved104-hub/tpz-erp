<?php

namespace Tests\Feature\Navigation;

use App\Enums\EmployeeRole;
use App\Enums\MarketplaceOperationsPermission;
use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Pages\CustomizeNavigation;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use App\Services\Search\NavigationSearchProvider;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class NavigationPhaseOneTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_panel_group_order_and_owner_destinations_follow_the_approved_structure(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame([
            'Workspace', 'Sales', 'Purchasing', 'Inventory', 'Marketplace', 'Returns & Service',
            'Products', 'People', 'HR', 'Finance', 'Reports', 'Administration',
        ], Filament::getCurrentPanel()->getNavigationGroups());

        $groups = app(NavigationPreferenceService::class)->authorizedNavigation();
        foreach ([
            'Tax Invoices' => 'Sales', 'Stock Ownership' => 'Inventory',
            'Suppliers' => 'Purchasing', 'Products' => 'Products',
            'Brands' => 'Administration', 'Categories' => 'Administration',
            'Platforms' => 'Administration', 'Responsibility Assignments' => 'People',
            'Employees' => 'People', 'Attendance' => 'HR', 'Activity Logs' => 'Administration',
        ] as $label => $group) {
            $items = collect($groups)->first(fn ($candidate) => $candidate->getLabel() === $group)?->getItems() ?? [];
            $this->assertTrue(collect($items)->contains(fn ($item) => $item->getLabel() === $label), "$label must appear in $group.");
        }

        foreach ([
            'Workspace' => ['My Work', 'Notifications', 'Chat', 'Tasks', 'Customize Navigation', 'Security'],
            'Sales' => ['Sales Orders', 'Web Sales Orders', 'Quotations', 'Tax Invoices', 'Web Sales Dashboard'],
            'Inventory' => ['My Inventory', 'Inventory Overview', 'Location Balances', 'Stock Requests', 'Stock Transfers', 'Damaged Items', 'QC Pending', 'Stock Adjustments', 'Stock Ownership'],
        ] as $label => $expected) {
            $group = collect($groups)->first(fn ($candidate) => $candidate->getLabel() === $label);
            $this->assertSame($expected, collect($group->getItems())->map->getLabel()->all());
        }

        $this->assertFalse($this->labels($groups)->contains('Invoices'));
        $this->assertFalse($this->labels($groups)->contains('Stock by Holder'));
        $this->assertNotContains('Products & Catalog', collect($groups)->map->getLabel()->all());
    }

    public function test_staff_and_manager_defaults_hide_advanced_and_specialist_links_without_changing_access(): void
    {
        foreach ([EmployeeRole::Staff, EmployeeRole::Manager] as $role) {
            $user = $this->responsibilityUser($role);
            $this->actingAs($user);
            $service = app(NavigationPreferenceService::class);
            $authorized = $service->authorizedNavigation();
            $personalized = $service->apply($user, $authorized);
            $this->assertNotContains('Administration', collect($personalized)->map->getLabel()->all());
            foreach (['Access Control', 'Inventory Allocations', 'Stock Ownership', 'Stock Movements', 'Reservations', 'Web Sales Dashboard'] as $label) {
                $this->assertFalse($this->labels($personalized)->contains($label));
            }
            $this->assertTrue($this->labels($personalized)->contains('Customize Navigation'));
            $this->assertTrue($this->labels($personalized)->contains('Attendance'));
            $this->get(AccessControl::getUrl())->assertForbidden();
        }
    }

    public function test_admin_retains_authorized_destinations_but_not_owner_only_ones(): void
    {
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $this->actingAs($admin);
        $service = app(NavigationPreferenceService::class);
        $authorized = $service->authorizedNavigation();
        $this->assertSame($this->labels($authorized)->all(), $this->labels($service->apply($admin, $authorized))->all());
        $this->assertTrue($this->labels($authorized)->contains('Stock Ownership'));
        $this->assertTrue($this->labels($authorized)->contains('Marketplace Operations'));
        $this->assertTrue($this->labels($authorized)->contains('Access Control'));
        $this->assertFalse(app(MarketplaceOperationsAuthorization::class)->allows($admin, MarketplaceOperationsPermission::Manage));
    }

    public function test_renamed_moved_items_and_split_groups_keep_existing_preferences(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $prefs = app(UserUiPreferenceService::class);
        $cases = [
            ['Products & Catalog', 'Suppliers', 'Purchasing', 'Suppliers', 'suppliers'],
            ['Products & Catalog', 'Brands', 'Administration', 'Brands', 'product-brands'],
            ['Administration', 'Responsibility Assignments', 'People', 'Responsibility Assignments', 'responsibility-assignments'],
            ['Sales', 'Invoices', 'Sales', 'Tax Invoices', 'tax-invoices'],
            ['Inventory', 'Stock by Holder', 'Inventory', 'Stock Ownership', 'inventory/stock-by-holder'],
            ['People & HR', 'Attendance', 'HR', 'Attendance', 'hr/attendance'],
            ['Reports', 'Activity Logs', 'Administration', 'Activity Logs', 'activity-logs'],
            ['Returns & Service', 'Returns', 'Returns & Service', 'Customer Returns', 'customer-returns'],
        ];
        foreach ($cases as [$oldGroup, $oldLabel, $group, $label, $path]) {
            $key = $this->key($oldGroup, $oldLabel, $path);
            $prefs->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$key]);
            $groups = [NavigationGroup::make($group)->items([NavigationItem::make($label)->url('/admin/'.$path)])];
            $this->assertSame([], app(NavigationPreferenceService::class)->apply($owner, $groups), $label);
            $this->assertSame([$key], $prefs->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
        }
        $prefs->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, []);
        $prefs->put($owner, UserUiPreferenceService::NAVIGATION_GROUP_ORDER, [
            'group:'.hash('sha256', 'People & HR'), 'group:'.hash('sha256', 'Products & Catalog'),
        ]);
        $groups = collect(['Products', 'People', 'HR'])->map(fn ($label) => NavigationGroup::make($label)->items([NavigationItem::make($label)->url('/admin/'.strtolower($label))]))->all();
        $this->assertSame(['People', 'HR', 'Products'], collect(app(NavigationPreferenceService::class)->apply($owner, $groups))->map->getLabel()->all());
    }

    public function test_default_hiding_can_be_customized_and_is_not_authorization(): void
    {
        $manager = $this->responsibilityUser(EmployeeRole::Manager);
        $groups = [NavigationGroup::make('Administration')->items([NavigationItem::make('Authorized Settings')->url('/admin/settings')])];
        $service = new class(app(UserUiPreferenceService::class), $groups) extends NavigationPreferenceService
        {
            public function __construct(UserUiPreferenceService $preferences, private array $groups)
            {
                parent::__construct($preferences);
            }

            public function authorizedNavigation(): array
            {
                return $this->groups;
            }
        };
        $this->assertSame([], $service->apply($manager, $groups));
        $this->assertTrue($service->customizerGroups($manager)[0]['items'][0]['hidden']);
        $service->saveHiddenItems($manager, []);
        $this->assertCount(1, $service->apply($manager, $groups));
        $other = $this->responsibilityUser(EmployeeRole::Manager);
        $this->assertSame([], $service->apply($other, $groups));
    }

    public function test_hidden_authorized_modules_remain_searchable_and_direct_urls_still_work(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);
        app(NavigationPreferenceService::class)->saveHiddenItems($owner, [$this->key('Products', 'Products', 'products')]);
        $this->assertFalse($this->labels(Filament::getNavigation())->contains('Products'), 'Personally hidden Products must be absent from the sidebar.');
        $results = app(NavigationSearchProvider::class)->search($owner, 'Products', 50);
        $this->assertSame(ProductResource::getUrl(), $results->firstWhere('label', 'Products')->url);
        $this->assertSame($results->count(), $results->pluck('url')->unique()->count());
        $this->get(ProductResource::getUrl())->assertOk();
    }

    public function test_unauthorized_modules_remain_absent_from_search_and_direct_routes(): void
    {
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $this->actingAs($staff);
        $this->assertFalse(app(NavigationSearchProvider::class)->search($staff, 'Access Control', 50)->contains('label', 'Access Control'), 'Unauthorized Access Control must be absent from search.');
        $this->get(AccessControl::getUrl())->assertForbidden();
    }

    public function test_customizer_can_unhide_legacy_items_without_deleting_unavailable_preferences(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->actingAs($owner);
        $legacy = $this->key('Products & Catalog', 'Suppliers', 'suppliers');
        $unavailable = 'item:'.str_repeat('a', 64);
        $prefs = app(UserUiPreferenceService::class);
        $prefs->put($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$legacy, $unavailable]);
        $service = app(NavigationPreferenceService::class);
        $supplier = collect($service->customizerGroups($owner))->flatMap(fn ($group) => $group['items'])->firstWhere('label', 'Suppliers');
        $this->assertTrue($supplier['hidden']);
        Livewire::test(CustomizeNavigation::class)->call('toggleNavigationItem', $supplier['key'])->assertHasNoErrors();
        $this->assertSame([$unavailable], $prefs->get($owner, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS));
        $this->assertTrue($this->labels(Filament::getNavigation())->contains('Suppliers'));
    }

    private function key(string $group, string $label, string $path): string
    {
        return 'item:'.hash('sha256', implode('|', [$group, '', $label, 'admin/'.$path]));
    }

    private function labels(array $groups): Collection
    {
        $flatten = function (NavigationItem $item) use (&$flatten): array {
            return [$item->getLabel(), ...collect($item->getChildItems())->flatMap($flatten)->all()];
        };

        return collect($groups)->flatMap(fn ($group) => collect($group->getItems())->flatMap($flatten));
    }
}
