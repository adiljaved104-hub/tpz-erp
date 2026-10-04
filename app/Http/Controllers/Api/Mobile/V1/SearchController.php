<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
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

        $groups = $search->search($request->user(), $query)
            ->map(fn (Collection $items, string $group): array => [
                'group' => $group,
                'items' => $items
                    ->filter(fn ($item): bool => $item->mobileTarget !== null)
                    ->map(fn ($item): array => [
                        'label' => $item->label,
                        'description' => $item->description,
                        'target' => $item->mobileTarget,
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
