<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dashboard\ErpDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        ErpDashboardService $dashboard,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('employee');

        $data = $dashboard->forUser(
            user: $user,
            period: 'today',
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
