<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\RenewedQcDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QcDispatchController extends MobileController
{
    public function home(Request $request, RenewedQcDispatchService $service): JsonResponse
    {
        $service->authorizeQueue($request->user());
        $clock = app(BusinessTimezone::class);
        $today = $clock->date(now())->startOfDay();
        $canViewInspections = app(QcAuthorization::class)->allows($request->user(), QcPermission::View);
        $mine = $canViewInspections ? app(QcInspectionService::class)->visible($request->user())->where('technician_user_id', $request->user()->id) : null;

        return response()->json(['data' => ['reserved_orders' => $service->queueQuery($request->user())->count(),
            'my_in_progress' => $mine ? (clone $mine)->where('status', QcInspectionStatus::InProgress->value)->count() : 0,
            'completed_today' => $mine ? (clone $mine)->where('status', QcInspectionStatus::Completed->value)->where('completed_at', '>=', $today->copy()->setTimezone('UTC'))->where('completed_at', '<', $today->copy()->addDay()->setTimezone('UTC'))->count() : 0,
            'timezone' => $clock->name(), 'capabilities' => ['queue' => '/workspace/qc/pending', 'dispatch' => '/workspace/qc/dispatch', 'individual_scan' => true, 'explicit_ship' => true, 'bulk_ship_limit' => 50]]]);
    }

    public function index(Request $request, RenewedQcDispatchService $service): JsonResponse
    {
        $input = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50', 'q' => 'nullable|string|max:100']);
        $query = $service->queueQuery($request->user());
        if (filled($input['q'] ?? null)) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$input['q'].'%')->orWhere('external_order_number', 'like', '%'.$input['q'].'%'));
        }

        return response()->json($query->paginate($input['per_page'] ?? 25)->through(fn ($order) => $service->detail($order, $request->user())));
    }

    public function show(Request $request, Order $order, RenewedQcDispatchService $service): JsonResponse
    {
        return response()->json(['data' => $service->detail($order, $request->user())]);
    }

    public function scan(Request $request, Order $order, RenewedQcDispatchService $service): JsonResponse
    {
        $input = $request->validate(['order_item_id' => 'required|integer', 'code' => 'required|string|max:2048']);

        return response()->json(['data' => $service->scan($order, $input['order_item_id'], $input['code'], $request->user()), 'message' => 'QC unit assigned. The Order remains Reserved until Mark Shipped.']);
    }

    public function ship(Request $request, Order $order, RenewedQcDispatchService $service): JsonResponse
    {
        $input = $request->validate(['idempotency_key' => 'required|uuid']);
        try {
            $order = $service->ship($order, $input['idempotency_key'], $request->user());
        } catch (InvalidOrderTransitionException $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return response()->json(['data' => ['order_id' => $order->id, 'reference' => $order->reference, 'status' => 'shipped', 'fulfilled_at' => app(BusinessTimezone::class)->iso($order->fulfillment->fulfilled_at)], 'message' => 'Order shipped.']);
    }

    public function bulkShip(Request $request, RenewedQcDispatchService $service): JsonResponse
    {
        $input = $request->validate(['orders' => 'required|array|min:1|max:50']);

        return response()->json(['data' => $service->bulkShip($input['orders'], $request->user())]);
    }
}
