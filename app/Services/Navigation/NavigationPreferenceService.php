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
        if ($this->workspaceModeEnabled($user)) {
            $groups = $this->workspaceGroups($groups);
        }
        $hidden = $this->hiddenItems($user, $groups);
        $order = $this->preferences->get($user, $this->groupOrderKey($user));

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

    public function workspaceModeEnabled(User $user): bool
    {
        return $this->eligibleForWorkspaceMode($user)
            && in_array('enabled', $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE), true);
    }

    public function setWorkspaceMode(User $user, bool $enabled): void
    {
        abort_unless($this->eligibleForWorkspaceMode($user), 403);
        $this->preferences->put($user, UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE, $enabled ? ['enabled'] : []);
    }

    public function eligibleForWorkspaceMode(User $user): bool
    {
        // Technicians retain the existing restricted QC-only application.
        // All other active employees can choose the new presentation, while
        // module authorization remains independently enforced.
        return $user->employee?->status === true
            && in_array($user->employee?->role, [
                EmployeeRole::Owner,
                EmployeeRole::Admin,
                EmployeeRole::Manager,
                EmployeeRole::Staff,
            ], true);
    }

    /** @return array<int, array{label: string, url: string, icon: string}> */
    public function workspaceModuleLinks(User $user, string $workspace): array
    {
        if (! $this->workspaceModeEnabled($user)) {
            return [];
        }

        // The source is Filament's already authorized navigation, not an
        // unrestricted resource lookup. These are the same destinations
        // that the Classic sidebar exposed to this account.
        $links = [];
        $append = function (NavigationItem $item, string $groupLabel) use (&$append, &$links, $workspace): void {
            $url = (string) $item->getUrl();
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $target = $path === 'admin/products'
                ? 'Inventory & Warehouse'
                : $this->workspaceTarget($groupLabel);

            if ($target === $workspace && $url !== '' && ! str_contains($path, 'workspace')) {
                $links[$url] = [
                    'label' => (string) $item->getLabel(),
                    'url' => $url,
                    'icon' => 'heroicon-o-arrow-up-right',
                ];
            }

            // Include existing nested sidebar items, too; the new sidebar
            // must never silently orphan accessible second-level modules.
            foreach ($item->getChildItems() as $child) {
                $append($child, $groupLabel);
            }
        };

        foreach ($this->authorizedNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $append($item, $group->getLabel() ?? '');
            }
        }

        return array_values($links);
    }

    private function workspaceTarget(string $label): string
    {
        return match ($label) {
            'Workspace' => 'Home',
            'Sales', 'Marketplace' => 'Sales',
            'Inventory', 'Products' => 'Inventory & Warehouse',
            'Purchasing' => 'Purchasing',
            'Returns & Service' => 'Returns & QC',
            'Finance', 'Accounting', 'Reports' => 'Finance & Accounting',
            'People', 'HR' => 'Team & HR',
            default => 'Administration',
        };
    }

    /** @param array<int, NavigationGroup> $groups @return array<int, NavigationGroup> */
    private function workspaceGroups(array $groups): array
    {
        // Preserve all native NavigationItem objects, destinations, and permission filtering.
        $labels = [
            'Workspace' => 'Home',
            'Sales' => 'Sales',
            'Marketplace' => 'Sales',
            'Inventory' => 'Inventory & Warehouse',
            'Products' => 'Inventory & Warehouse',
            'Purchasing' => 'Purchasing',
            'Returns & Service' => 'Returns & QC',
            'Finance' => 'Finance & Accounting',
            'Accounting' => 'Finance & Accounting',
            'Reports' => 'Finance & Accounting',
            'People' => 'Team & HR',
            'HR' => 'Team & HR',
            'Administration' => 'Administration',
        ];
        $canonicalOrder = [
            'Home',
            'Sales',
            'Inventory & Warehouse',
            'Purchasing',
            'Returns & QC',
            'Finance & Accounting',
            'Team & HR',
            'Administration',
        ];
        $result = [];

        foreach ($groups as $group) {
            $label = $labels[$group->getLabel() ?? ''] ?? ($group->getLabel() ?? 'Administration');
            foreach ($group->getItems() as $item) {
                // Product catalog is a Warehouse workflow in the new layout, even
                // though its original resource is registered under Purchasing.
                $path = trim((string) (parse_url((string) $item->getUrl(), PHP_URL_PATH) ?? ''), '/');
                $target = $path === 'admin/products' ? 'Inventory & Warehouse' : $label;
                if (! isset($result[$target])) {
                    // Keep every authorized resource, but begin with compact
                    // workspace groups. Filament lets users expand any group.
                    $result[$target] = NavigationGroup::make($target)
                        ->items([])
                        ->collapsible();
                    if ($target !== 'Home') {
                        $result[$target]->collapsed();
                    }
                }

                $result[$target]->items([...$result[$target]->getItems(), $item]);
            }
        }

        // Display each authorized workspace as one first-level sidebar link.
        // Covered module destinations are presented in its workspace tabs.
        // Preserve a fallback for an unusual account that has access to a
        // resource but lacks the related workspace (never lose navigation).
        $workspaceNames = [
            'Sales' => 'Sales Workspace',
            'Inventory & Warehouse' => 'Inventory Workspace',
            'Purchasing' => 'Purchasing Workspace',
            'Returns & QC' => 'Returns & QC Workspace',
            'Finance & Accounting' => 'Finance & Accounting Workspace',
            'Team & HR' => 'Team & HR Workspace',
            'Administration' => 'Administration Workspace',
        ];
        $firstLevel = [];
        $uncovered = [];
        foreach (array_values(array_unique([...$canonicalOrder, ...array_keys($result)])) as $label) {
            $group = $result[$label] ?? null;
            if ($group === null) {
                continue;
            }
            $items = collect($group->getItems());
            if ($label === 'Home') {
                $dashboard = $items->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Dashboard');
                if ($dashboard !== null) {
                    $firstLevel[] = $dashboard;
                }
                $uncovered = [...$uncovered, ...$items->reject(fn (NavigationItem $item): bool => $item === $dashboard)->all()];
                continue;
            }
            $name = $workspaceNames[$label] ?? null;
            $primary = $items->first(fn (NavigationItem $item): bool => $item->getLabel() === $name);
            if ($primary !== null) {
                $firstLevel[] = $primary;
            } else {
                $uncovered = [...$uncovered, ...$items->all()];
            }
        }
        $navigation = [];
        if ($firstLevel !== []) {
            $navigation[] = NavigationGroup::make()->items($firstLevel);
        }
        if ($uncovered !== []) {
            $navigation[] = NavigationGroup::make('Additional Modules')
                ->items($uncovered)->collapsible()->collapsed();
        }

        return $navigation;
    }

    /** @return array<int, array{key:string,label:string,items:array<int,array{key:string,label:string,parent:?string,hidden:bool,protected:bool}>}> */
    public function customizerGroups(User $user): array
    {
        $groups = $this->authorizedNavigation();
        if ($this->workspaceModeEnabled($user)) {
            $groups = $this->workspaceGroups($groups);
        }
        $hidden = $this->hiddenItems($user, $groups);
        $order = $this->preferences->get($user, $this->groupOrderKey($user));
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
        $key = $this->hiddenItemsKey($user);
        $previous = $this->preferences->get($user, $key);
        $retained = array_values(array_diff($previous, $knownKeys));
        $this->preferences->put($user, $key, array_values(array_unique([...$hidden, ...$retained])));
    }

    /** @param array<int, string> $order */
    public function saveGroupOrder(User $user, array $order): void
    {
        $allowed = collect($this->customizerGroups($user))->pluck('key')->all();
        if (count($order) !== count(array_unique($order)) || array_diff($order, $allowed) !== [] || array_diff($allowed, $order) !== []) {
            throw ValidationException::withMessages(['group_order' => 'The navigation group order is invalid.']);
        }

        $this->preferences->put($user, $this->groupOrderKey($user), array_values($order));
    }

    public function reset(User $user): void
    {
        $this->preferences->forget(
            $user,
            UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS,
            UserUiPreferenceService::NAVIGATION_GROUP_ORDER,
            UserUiPreferenceService::NAVIGATION_WORKSPACE_MODE,
            UserUiPreferenceService::NAVIGATION_WORKSPACE_HIDDEN_ITEMS,
            UserUiPreferenceService::NAVIGATION_WORKSPACE_GROUP_ORDER,
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
            'admin/my-inventory' => ['My Inventory'],
            'admin/inventory-overview' => ['Inventory Overview', 'Inventory Overview by Location'],
            'admin/product-inventories' => ['Stock by Location', 'Location Balances'],
            default => [],
        }];
        $labels = [...$labels, ...match ($item->getLabel()) {
            'Tax Invoices' => ['Invoices'],
            'Stock Ownership' => ['Stock by Holder'],
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
            'Home' => ['Workspace'],
            'Sales' => ['Marketplace'],
            'Inventory & Warehouse' => ['Inventory', 'Products', 'Purchasing'],
            'Returns & QC' => ['Returns & Service'],
            'Finance & Accounting' => ['Finance', 'Accounting', 'Reports'],
            'Team & HR' => ['People', 'HR'],
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

        // The workspace view inherits existing hidden items on first enable,
        // but edits stay independent so switching back restores the legacy layout.
        if ($this->workspaceModeEnabled($user)) {
            $legacy = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, $defaults);

            return $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_WORKSPACE_HIDDEN_ITEMS, $legacy);
        }

        // A saved empty list means the user explicitly chose to show every authorized item.
        return $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, $defaults);
    }

    private function hiddenItemsKey(User $user): string
    {
        return $this->workspaceModeEnabled($user)
            ? UserUiPreferenceService::NAVIGATION_WORKSPACE_HIDDEN_ITEMS
            : UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS;
    }

    private function groupOrderKey(User $user): string
    {
        return $this->workspaceModeEnabled($user)
            ? UserUiPreferenceService::NAVIGATION_WORKSPACE_GROUP_ORDER
            : UserUiPreferenceService::NAVIGATION_GROUP_ORDER;
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
