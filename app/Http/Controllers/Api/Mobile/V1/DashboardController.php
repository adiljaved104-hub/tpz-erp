<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\ErpDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        ErpDashboardService $dashboard,
    ): JsonResponse {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'custom'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $period = $validated['period'] ?? 'today';

        if ($period === 'custom' && (
            empty($validated['from']) ||
            empty($validated['to'])
        )) {
            return response()->json([
                'message' => 'Custom period requires both from and to dates.',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('employee');

        $data = $dashboard->forUser(
            user: $user,
            period: $period,
            customFrom: $validated['from'] ?? null,
            customTo: $validated['to'] ?? null,
            inventoryScope: 'employee',
            inventoryEmployeeId: $user->employee->id,
            widgetKeys: [
                'attention',
                'sales',
                'inventory',
                'service',
                'work',
                'responsibilities',
            ],
        );

        return response()->json([
            'data' => $data,
        ]);
    }
}
