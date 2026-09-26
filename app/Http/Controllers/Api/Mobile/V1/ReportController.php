<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $catalog = app(ReportCatalog::class);

        $reports = collect($catalog->available($user))
            ->map(function (array $definition, string $key) use ($catalog, $user): array {
                return [
                    'key' => $key,
                    'title' => $definition['title'],
                    'group' => $definition['group'],
                    'period' => (bool) $definition['period'],
                    'filters' => $definition['filters'],
                    'formats' => collect($definition['formats'])
                        ->filter(fn (string $format): bool => $catalog->canExport($user, $key, $format))
                        ->values()
                        ->all(),
                    'financial_sensitive' => (bool) $definition['financial_sensitive'],
                ];
            })
            ->values();

        return response()->json(['data' => $reports]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $user = $request->user();
        $catalog = app(ReportCatalog::class);
        $definition = $catalog->get($user, $report);

        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|string|max:100',
            'employee_id' => 'nullable|integer',
            'team_id' => 'nullable|integer',
            'platform_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'supplier_id' => 'nullable|integer',
            'category' => 'nullable|string|max:100',
            'cost_center' => 'nullable|string|max:100',
            'channel' => 'nullable|string|max:100',
            'office_account_id' => 'nullable|integer',
            'product_search' => 'nullable|string|max:100',
        ]);

        $queries = app(ReportQueryService::class);

        $result = $queries->run(
            $user,
            $report,
            $filters,
        );

        $options = $queries->filterOptions(
            $user,
            $definition['filters'],
            $filters['product_search'] ?? '',
            isset($filters['product_id']) ? (int) $filters['product_id'] : null,
        );

        return response()->json([
            'data' => [
                'definition' => [
                    'key' => $report,
                    'title' => $definition['title'],
                    'group' => $definition['group'],
                    'period' => (bool) $definition['period'],
                    'filters' => $definition['filters'],
                    'financial_sensitive' => (bool) $definition['financial_sensitive'],
                ],
                'columns' => $result->columns,
                'rows' => $result->rows->values()->all(),
                'summary' => $result->summary,
                'filters' => $result->filters,
                'total_rows' => $result->totalRows,
                'options' => $options,
                'export_formats' => collect($definition['formats'])
                    ->filter(fn (string $format): bool => $catalog->canExport($user, $report, $format))
                    ->values()
                    ->all(),
            ],
        ]);
    }
}