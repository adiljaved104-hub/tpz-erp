<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Models\QcRecordedStockIntake;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcRecordedRenewedStockIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QcRecordedStockIntakeController extends MobileController
{
    public function index(Request $request, QcRecordedRenewedStockIntakeService $intakes, QcOrderDemandService $demand): JsonResponse
    {
        $priority = $demand->calculateForQcQueue($request->user());

        return response()->json([
            'data' => $intakes->queue($request->user(), $priority['by_product_location']),
            'priority' => ['needed_for_orders' => $priority['units_needed']],
        ]);
    }

    public function start(QcRecordedStockIntake $intake, Request $request, QcRecordedRenewedStockIntakeService $intakes, QcInspectionService $inspections): JsonResponse
    {
        $input = $request->validate([
            'serial' => ['required', 'string', 'min:3', 'max:100'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string'],
            'special_requirement' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $source = $intakes->findForStart($intake->id, $request->user());
        $inspection = $inspections->start([
            ...$input,
            'product_id' => $source->product_id,
            'warehouse_id' => $source->warehouse_id,
            'recorded_stock_intake_id' => $source->id,
        ], $request->user());

        $source->refresh();
        $remainingQuantity = max(0, (int) $source->accepted_quantity - (int) $source->started_quantity);

        return response()->json([
            'inspection_id' => $inspection->id,
            'qc_id' => $inspection->device()->value('reference'),
            'status' => $inspection->status->value,
            'remaining_quantity' => $remainingQuantity,
        ], 201);
    }
}
