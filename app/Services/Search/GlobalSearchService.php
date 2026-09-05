<?php

namespace App\Services\Search;

use App\DTOs\GlobalSearchResult;
use App\Models\User;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    public const MIN_QUERY_LENGTH = 2;

    public const RESULTS_PER_GROUP = 6;

    /** @return Collection<string, Collection<int, GlobalSearchResult>> */
    public function search(User $user, string $query): Collection
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH || $user->employee?->status !== true) {
            return collect();
        }

        return collect([
            app(CatalogPeopleSearchProvider::class),
            app(SalesServiceSearchProvider::class),
            app(PurchasingSearchProvider::class),
            app(OperationsSearchProvider::class),
            app(HrSearchProvider::class),
        ])->flatMap(fn ($provider) => $provider->search($user, $query, self::RESULTS_PER_GROUP))
            ->groupBy('group');
    }
}
