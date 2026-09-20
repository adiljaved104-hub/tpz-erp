<?php

namespace App\Services\Navigation;

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
        $hidden = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS);
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
            $positions[$this->groupKey($group->getLabel())] ?? PHP_INT_MAX,
            $index,
        ])->values()->all();
    }

    /** @return array<int, array{key:string,label:string,items:array<int,array{key:string,label:string,parent:?string,hidden:bool,protected:bool}>}> */
    public function customizerGroups(User $user): array
    {
        $groups = $this->authorizedNavigation();
        $hidden = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS);
        $order = $this->preferences->get($user, UserUiPreferenceService::NAVIGATION_GROUP_ORDER);
        $positions = array_flip($order);

        return collect($groups)->map(function (NavigationGroup $group) use ($hidden): array {
            $label = $group->getLabel() ?? 'Other';

            return [
                'key' => $this->groupKey($group->getLabel()),
                'label' => $label,
                'items' => $this->itemDefinitions(collect($group->getItems()), $label, '', $hidden),
            ];
        })->sortBy(fn (array $group, int $index): array => [$positions[$group['key']] ?? PHP_INT_MAX, $index])
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

        $this->preferences->put($user, UserUiPreferenceService::NAVIGATION_HIDDEN_ITEMS, array_values($hidden));
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
        if (! $this->isProtected($item) && in_array($key, $hidden, true)) {
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
                'hidden' => in_array($key, $hidden, true),
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

    private function isProtected(NavigationItem $item): bool
    {
        $path = trim((string) (parse_url((string) $item->getUrl(), PHP_URL_PATH) ?? ''), '/');

        return str_ends_with($path, 'customize-navigation');
    }
}
