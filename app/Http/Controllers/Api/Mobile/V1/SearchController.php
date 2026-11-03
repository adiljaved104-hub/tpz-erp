<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Services\Mobile\MobileSearchService;
use App\Services\Mobile\MobileWorkspaceCapabilities;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search, MobileSearchService $mobileSearch): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = trim((string) ($data['q'] ?? ''));

        if (mb_strlen($query) < GlobalSearchService::MIN_QUERY_LENGTH) {
            return response()->json([
                'data' => [],
                'minimum_query_length' => GlobalSearchService::MIN_QUERY_LENGTH,
            ]);
        }

        $recordResults = app(MobileWorkspaceCapabilities::class)->isQcFocused($request->user())
            ? collect()
            : $search->search($request->user(), $query)
                ->flatMap(fn (Collection $items, string $group): Collection => $items
                    ->filter(fn ($item): bool => $item->mobileTarget !== null)
                    ->map(fn ($item): array => [
                        'group' => $group,
                        'label' => $item->label,
                        'description' => $item->description,
                        'target' => $item->mobileTarget,
                    ]));
        $groups = $mobileSearch->search(
            $request->user(),
            $query,
            GlobalSearchService::RESULTS_PER_GROUP,
        )->concat($recordResults)
            ->groupBy('group')
            ->map(fn (Collection $items, string $group): array => [
                'group' => $group,
                'items' => $items
                    ->unique(fn (array $item): string => $item['target']['module'].':'.$item['target']['id'])
                    ->take(GlobalSearchService::RESULTS_PER_GROUP)
                    ->map(fn (array $item): array => [
                        'label' => $item['label'],
                        'description' => $item['description'],
                        'target' => $item['target'],
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['items'] !== [])
            ->values();

        return response()->json([
            'data' => $groups,
            'minimum_query_length' => GlobalSearchService::MIN_QUERY_LENGTH,
        ]);
    }
}
