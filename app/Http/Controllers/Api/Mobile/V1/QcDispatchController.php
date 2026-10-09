<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\OrderPermission;
use App\Enums\QcPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionReadService;
use App\Services\Qc\RenewedQcDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QcDispatchController extends MobileController
{
    public function home(Request $request, RenewedQcDispatchService $service, QcInspectionReadService $inspections, QcAuthorization $qcAuthorization, OrderAuthorization $orderAuthorization): JsonResponse
    {
        $actor = $request->user();
        $canInspect = $qcAuthorization->allows($actor, QcPermission::View);
        $canDispatch = $qcAuthorization->allows($actor, QcPermission::ViewDispatchQueue)
            && $orderAuthorization->allows($actor, OrderPermission::View);
        abort_unless($canInspect || $canDispatch, 403);

        $canScan = $canDispatch
            && $qcAuthorization->allows($actor, QcPermission::ScanDispatch)
            && $qcAuthorization->allows($actor, QcPermission::ViewOrderAssignments)
            && $qcAuthorization->allows($actor, QcPermission::AssignOrderDevice);
        $canShip = $canDispatch && $qcAuthorization->allows($actor, QcPermission::ShipDispatch)
            && $orderAuthorization->allows($actor, OrderPermission::Fulfill);
        $clock = app(BusinessTimezone::class);

        return response()->json(['data' => ['reserved_orders' => $canDispatch ? $service->queueQuery($actor)->count() : 0,
            ...$inspections->counters($actor),
            'timezone' => $clock->name(), 'capabilities' => [
                'inspections' => '/workspace/qc/inspections',
                'queue' => '/workspace/qc/pending',
                'dispatch' => '/workspace/qc/dispatch',
                'individual_scan' => $canScan,
                'explicit_ship' => $canShip,
                'bulk_ship_limit' => $canShip ? 50 : 0,
                'access' => ['inspections' => $canInspect, 'dispatch' => $canDispatch, 'scan' => $canScan, 'ship' => $canShip],
            ]]]);
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

    public function verifiedScan(Request $request, Order $order, RenewedQcDispatchService $service): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:2048'], 'physical_serial' => ['required', 'string', 'min:3', 'max:100', 'regex:/\A[A-Za-z0-9._ -]+\z/'], 'order_item_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $service->verifiedScan($order, $input['code'], $input['physical_serial'], $input['order_item_id'] ?? null, $request->user()), 'message' => 'QC unit matched and assigned. The Order remains Reserved until Mark Shipped.']);
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
