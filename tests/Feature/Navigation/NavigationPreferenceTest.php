<?php

namespace Tests\Feature\Navigation;

use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Preferences\UserUiPreferenceService;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_items_can_be_hidden_per_user_without_exposing_missing_items(): void
    {
        [$inventory, $sales] = $this->navigationGroups();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $service = app(NavigationPreferenceService::class);
        $itemKey = $this->itemKey('Inventory', 'My Inventory', 'admin/my-inventory');

        app(UserUiPreferenceService::class)->put($first, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$itemKey]);
        UserUiPreference::query()->create([
            'user_id' => $first->id,
            'preference_key' => UserUiPreferenceService::NAVIGATION_GROUP_ORDER,
            'preference_value' => ['group:'.str_repeat('0', 64)],
        ]);

        $firstGroups = $service->apply($first, [$inventory, $sales]);
        $secondGroups = $service->apply($second, [$inventory, $sales]);

        $this->assertSame(['Products'], collect($firstGroups[0]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all());
        $this->assertSame(['My Inventory', 'Products'], collect($secondGroups[0]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all());
        $this->assertCount(2, $firstGroups);
    }

    public function test_group_order_and_reset_are_per_user_and_new_authorized_items_remain_visible(): void
    {
        [$inventory, $sales] = $this->navigationGroups();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = app(NavigationPreferenceService::class);
        $salesKey = $this->groupKey('Sales');
        $inventoryKey = $this->groupKey('Inventory');

        app(UserUiPreferenceService::class)->put($user, UserUiPreferenceService::NAVIGATION_GROUP_ORDER, [$salesKey, $inventoryKey]);
        $ordered = $service->apply($user, [$inventory, $sales]);

        $this->assertSame(['Sales', 'Inventory'], collect($ordered)->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all());
        $this->assertSame(['Inventory', 'Sales'], collect($service->apply($other, [$inventory, $sales]))->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all());
        $this->assertContains('Products', collect($ordered[1]->getItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all());

        $service->reset($user);
        $this->assertSame(['Inventory', 'Sales'], collect($service->apply($user, [$inventory, $sales]))->map(fn (NavigationGroup $group): ?string => $group->getLabel())->all());
        $this->assertDatabaseMissing('user_ui_preferences', ['user_id' => $user->id]);
    }

    public function test_customize_navigation_item_cannot_be_hidden(): void
    {
        $user = User::factory()->create();
        $service = app(NavigationPreferenceService::class);
        $protected = NavigationGroup::make('Account')->items([
            NavigationItem::make('Customize Navigation')->url('/admin/customize-navigation'),
        ]);
        $key = $this->itemKey('Account', 'Customize Navigation', 'admin/customize-navigation');

        app(UserUiPreferenceService::class)->put($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, [$key]);
        $result = $service->apply($user, [$protected]);

        $this->assertSame('Customize Navigation', collect($result[0]->getItems())->sole()->getLabel());
    }

    /** @return array{NavigationGroup, NavigationGroup} */
    private function navigationGroups(): array
    {
        return [
            NavigationGroup::make('Inventory')->items([
                NavigationItem::make('My Inventory')->url('/admin/my-inventory'),
                NavigationItem::make('Products')->url('/admin/products'),
            ]),
            NavigationGroup::make('Sales')->items([
                NavigationItem::make('Orders')->url('/admin/orders'),
            ]),
        ];
    }

    private function groupKey(string $label): string
    {
        return 'group:'.hash('sha256', $label);
    }

    private function itemKey(string $group, string $label, string $path): string
    {
        return 'item:'.hash('sha256', implode('|', [$group, '', $label, $path]));
    }
}
