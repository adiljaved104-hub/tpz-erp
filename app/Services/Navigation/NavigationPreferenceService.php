<?php

namespace App\Services\Navigation;

use App\Enums\EmployeeRole;
use App\Models\User;
use App\Services\Preferences\UserUiPreferenceService;
use Closure;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class NavigationPreferenceService
{
    private bool $bypassing = false;

    public function __construct(private readonly UserUiPreferenceService $preferences) {}

    public function isBypassing(): bool
    {
        return $this->bypassing;
    }

    /** @return array<int, NavigationGroup> */
    public function authorizedNavigation(): array
    {
        return $this->withoutPersonalization(fn (): array => Filament::getNavigation());
    }

    public function withoutPersonalization(Closure $callback): mixed
    {
        $previous = $this->bypassing;
        $this->bypassing = true;

        try {
            return $callback();
        } finally {
            $this->bypassing = $previous;
        }
    }

    /** @param array<int, NavigationGroup> $groups @return array<int, NavigationGroup> */
    public function apply(User $user, array $groups): array
    {
        $hidden = $this->hiddenItems($user, $groups);
        $order = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_GROUP_ORDER);

        $filtered = collect($groups)->map(function (NavigationGroup $group) use ($hidden): ?NavigationGroup {
            $group = clone $group;
            $groupLabel = $group->getLabel() ?? '';
            $items = collect($group->getItems())
                ->map(fn (NavigationItem $item): ?NavigationItem => $this->filteredItem($item, $groupLabel, '', $hidden))
                ->filter()
                ->values();

            return $items->isEmpty() ? null : $group->items($items);
        })->filter()->values();

        $positions = array_flip($order);

        return $filtered->sortBy(fn (NavigationGroup $group, int $index): array => [
            $this->savedGroupPosition($group->getLabel(), $positions),
            $index,
        ])->values()->all();
    }

    /** @return array<int, array{key:string,label:string,items:array<int,array{key:string,label:string,parent:?string,hidden:bool,protected:bool}>}> */
    public function customizerGroups(User $user): array
    {
        $groups = $this->authorizedNavigation();
        $hidden = $this->hiddenItems($user, $groups);
        $order = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_GROUP_ORDER);
        $positions = array_flip($order);

        return collect($groups)->map(function (NavigationGroup $group) use ($hidden): array {
            $label = $group->getLabel() ?? 'Other';

            return [
                'key' => $this->groupKey($group->getLabel()),
                'label' => $label,
                'items' => $this->itemDefinitions(collect($group->getItems()), $label, '', $hidden),
            ];
        })->values()
            ->sortBy(fn (array $group, int $index): array => [$this->savedGroupPosition($group['label'], $positions), $index])
            ->values()->all();
    }

    /** @param array<int, string> $hidden */
    public function saveHiddenItems(User $user, array $hidden): void
    {
        $groups = $this->customizerGroups($user);
        $allowed = collect($groups)->flatMap(fn (array $group): array => $group['items'])
            ->reject(fn (array $item): bool => $item['protected'])
            ->pluck('key')->all();

        if (array_diff($hidden, $allowed) !== [] || count($hidden) !== count(array_unique($hidden))) {
            throw ValidationException::withMessages(['hidden_items' => 'The navigation visibility selection is invalid.']);
        }

        // Retain choices for temporarily unauthorized items, while replacing known legacy aliases.
        $knownKeys = $this->allItemKeys($this->authorizedNavigation());
        $previous = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS);
        $retained = array_values(array_diff($previous, $knownKeys));
        $this->preferences->put($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, array_values(array_unique([...$hidden, ...$retained])));
    }

    /** @param array<int, string> $order */
    public function saveGroupOrder(User $user, array $order): void
    {
        $allowed = collect($this->customizerGroups($user))->pluck('key')->all();
        if (count($order) !== count(array_unique($order)) || array_diff($order, $allowed) !== [] || array_diff($allowed, $order) !== []) {
            throw ValidationException::withMessages(['group_order' => 'The navigation group order is invalid.']);
        }

        $this->preferences->put($user, UserUiPreferenceService::NAVIGATION_GROUP_ORDER, array_values($order));
    }

    public function reset(User $user): void
    {
        $this->preferences->forget(
            $user,
            UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS,
            UserUiPreferenceService::NAVIGATION_GROUP_ORDER,
        );
    }

    /** @param array<int, string> $hidden */
    private function filteredItem(NavigationItem $item, string $group, string $parent, array $hidden): ?NavigationItem
    {
        $item = clone $item;
        $key = $this->itemKey($item, $group, $parent);
        if (! $this->isProtected($item) && $this->itemIsHidden($item, $group, $parent, $hidden)) {
            return null;
        }

        $children = collect($item->getChildItems())
            ->map(fn (NavigationItem $child): ?NavigationItem => $this->filteredItem($child, $group, $key, $hidden))
            ->filter()->values()->all();
        $item->childItems($children);

        if (blank($item->getUrl()) && $children === []) {
            return null;
        }

        return $item;
    }

    /** @param Collection<int, NavigationItem> $items @param array<int, string> $hidden @return array<int, array{key:string,label:string,parent:?string,hidden:bool,protected:bool}> */
    private function itemDefinitions(Collection $items, string $group, string $parent, array $hidden): array
    {
        return $items->flatMap(function (NavigationItem $item) use ($group, $parent, $hidden): array {
            $key = $this->itemKey($item, $group, $parent);
            $definition = [[
                'key' => $key,
                'label' => $item->getLabel(),
                'parent' => $parent === '' ? null : $parent,
                'hidden' => $this->itemIsHidden($item, $group, $parent, $hidden),
                'protected' => $this->isProtected($item),
            ]];

            return [...$definition, ...$this->itemDefinitions(collect($item->getChildItems()), $group, $key, $hidden)];
        })->values()->all();
    }

    private function groupKey(?string $label): string
    {
        return 'group:'.hash('sha256', (string) $label);
    }

    private function itemKey(NavigationItem $item, string $group, string $parent): string
    {
        $path = (string) (parse_url((string) $item->getUrl(), PHP_URL_PATH) ?? '');

        return 'item:'.hash('sha256', implode('|', [$group, $parent, $item->getLabel(), trim($path, '/')]));
    }

    /** Keep existing custom-navigation choices working when default groups or labels are reorganized. */
    private function itemIsHidden(NavigationItem $item, string $group, string $parent, array $hidden): bool
    {
        return array_intersect($this->compatibleItemKeys($item, $group, $parent), $hidden) !== [];
    }

    /** @return array<int, string> */
    private function compatibleItemKeys(NavigationItem $item, string $group, string $parent): array
    {
        $labels = [$item->getLabel()];
        $path = trim((string) (parse_url((string) $item->getUrl(), PHP_URL_PATH) ?? ''), '/');
        $labels = [...$labels, ...match ($path) {
            'admin/web-sales-orders' => ['Orders'],
            'admin/web-sales-dashboard' => ['Dashboard'],
            'admin/orders' => ['Orders'],
            default => [],
        }];
        $labels = [...$labels, ...match ($item->getLabel()) {
            'Tax Invoices' => ['Invoices'],
            'Stock Ownership' => ['Stock by Holder'],
            'Stock by Location' => ['Location Balances'],
            'Customer Returns' => ['Returns'],
            'Claims / Safe-T' => ['Claims'],
            'HR Settings' => ['Settings'],
            default => [],
        }];
        $previousGroups = match ($item->getLabel()) {
            'Suppliers', 'Brands', 'Categories', 'Platforms' => ['Products & Catalog', 'Catalog'],
            'Responsibility Assignments' => ['Administration'],
            'Activity Logs' => ['Reports', 'Analytics'],
            default => [],
        };
        $groups = array_unique([$group, ...$this->legacyGroupLabels($group), ...$previousGroups]);
        $keys = [];

        foreach ($groups as $candidateGroup) {
            foreach ($labels as $label) {
                $candidate = 'item:'.hash('sha256', implode('|', [$candidateGroup, $parent, $label, $path]));
                $keys[] = $candidate;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string, int> $positions */
    private function savedGroupPosition(?string $label, array $positions): int
    {
        $keys = [$this->groupKey($label), ...array_map($this->groupKey(...), $this->legacyGroupLabels((string) $label))];
        $saved = array_values(array_filter(array_map(fn (string $key): ?int => $positions[$key] ?? null, $keys), fn (?int $position): bool => $position !== null));

        return $saved === [] ? PHP_INT_MAX : min($saved);
    }

    /** @return array<int, string> */
    private function legacyGroupLabels(string $label): array
    {
        return match ($label) {
            'Workspace' => ['Work', 'Account'],
            'Products' => ['Products & Catalog', 'Catalog'],
            'Products & Catalog' => ['Catalog'],
            'Marketplace' => ['Sales'],
            'Returns & Service' => ['Returns', 'Service', 'Sales'],
            'People & HR' => ['People', 'HR'],
            'People' => ['People & HR'],
            'HR' => ['People & HR'],
            'Reports' => ['Analytics', 'Purchasing', 'Inventory'],
            default => [],
        };
    }

    /** @param array<int, NavigationGroup> $groups @return array<int, string> */
    private function hiddenItems(User $user, array $groups): array
    {
        $defaults = [];
        if (in_array($user->employee?->role, [EmployeeRole::Staff, EmployeeRole::Manager], true)) {
            foreach ($groups as $group) {
                foreach ($this->itemDefinitions(collect($group->getItems()), $group->getLabel() ?? 'Other', '', []) as $item) {
                    $advanced = $group->getLabel() === 'Administration';
                    $specialistReport = $group->getLabel() === 'Reports' && ! in_array($item['label'], ['Reports', 'Responsibility Reports'], true);
                    if (! $item['protected'] && ($advanced || $specialistReport || $item['label'] === 'Web Sales Dashboard')) {
                        $defaults[] = $item['key'];
                    }
                }
            }
        }

        // A saved empty list means the user explicitly chose to show every authorized item.
        return $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, $defaults);
    }

    /** @param array<int, NavigationGroup> $groups @return array<int, string> */
    private function allItemKeys(array $groups): array
    {
        $keys = [];
        $walk = function (NavigationItem $item, string $group, string $parent) use (&$walk, &$keys): void {
            $keys = [...$keys, ...$this->compatibleItemKeys($item, $group, $parent)];
            foreach ($item->getChildItems() as $child) {
                $walk($child, $group, $this->itemKey($item, $group, $parent));
            }
        };
        foreach ($groups as $group) {
            foreach ($group->getItems() as $item) {
                $walk($item, $group->getLabel() ?? 'Other', '');
            }
        }

        return array_values(array_unique($keys));
    }

    private function isProtected(NavigationItem $item): bool
    {
        $path = trim((string) (parse_url((string) $item->getUrl(), PHP_URL_PATH) ?? ''), '/');

        return str_ends_with($path, 'customize-navigation');
    }
}
