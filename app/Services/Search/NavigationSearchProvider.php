<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NavigationSearchProvider implements GlobalSearchProvider
{
    /** @return Collection<int, GlobalSearchResult> */
    public function search(User $user, string $query, int $limit): Collection
    {
        if (! auth()->user()?->is($user)) {
            return collect();
        }

        $needle = Str::lower(trim($query));

        return collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn (NavigationGroup $group): Collection => $this->resultsForItems(
                collect($group->getItems()),
                $group->getLabel(),
            ))
            ->filter(fn (GlobalSearchResult $result): bool => Str::contains(
                Str::lower($result->label.' '.$result->description),
                $needle,
            ))
            ->unique('url')
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, NavigationItem>  $items
     * @return Collection<int, GlobalSearchResult>
     */
    private function resultsForItems(Collection $items, ?string $group, ?string $parent = null): Collection
    {
        return $items->filter(fn (NavigationItem $item): bool => $item->isVisible())
            ->flatMap(function (NavigationItem $item) use ($group, $parent): Collection {
                $label = $item->getLabel();
                $description = collect([$group, $parent])->filter()->implode(' · ') ?: 'ERP';
                $result = filled($item->getUrl())
                    ? collect([new GlobalSearchResult(
                        'Modules & Pages',
                        $label,
                        $description,
                        $item->getUrl(),
                        'heroicon-o-squares-2x2',
                    )])
                    : collect();

                return $result->concat($this->resultsForItems(
                    collect($item->getChildItems()),
                    $group,
                    $label,
                ));
            });
    }
}
