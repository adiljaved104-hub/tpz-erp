<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\OrderPermission;
use App\Enums\QcPermission;
use App\Enums\StockTransferPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionReadService;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcTransferDemandService;
use App\Services\Qc\RenewedQcDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QcDispatchController extends MobileController
{
    public function home(Request $request, RenewedQcDispatchService $service, QcInspectionReadService $inspections, QcOrderDemandService $demand, QcTransferDemandService $transferDemand, QcAuthorization $qcAuthorization, OrderAuthorization $orderAuthorization, StockTransferAuthorization $transferAuthorization): JsonResponse
    {
        $actor = $request->user();
        $canInspect = $qcAuthorization->allows($actor, QcPermission::View);
        $canDispatch = $qcAuthorization->allows($actor, QcPermission::ViewDispatchQueue)
            && $orderAuthorization->allows($actor, OrderPermission::View);
        $canTransferQueue = $qcAuthorization->allows($actor, QcPermission::ViewOrderAssignments)
            && $transferAuthorization->allows($actor, StockTransferPermission::View);
        abort_unless($canInspect || $canDispatch || $canTransferQueue, 403);

        $canScan = $canDispatch
            && $qcAuthorization->allows($actor, QcPermission::ScanDispatch)
            && $qcAuthorization->allows($actor, QcPermission::ViewOrderAssignments)
            && $qcAuthorization->allows($actor, QcPermission::AssignOrderDevice);
        $canShip = $canDispatch && $qcAuthorization->allows($actor, QcPermission::ShipDispatch)
            && $orderAuthorization->allows($actor, OrderPermission::Fulfill);
        $transferSummary = $canTransferQueue ? $transferDemand->summary($actor)
            : ['units_needed' => 0, 'transfers_affected' => 0, 'marketplace_transfers' => 0];
        $canAssignTransfer = $canTransferQueue
            && $transferAuthorization->allows($actor, StockTransferPermission::Dispatch)
            && $qcAuthorization->allows($actor, QcPermission::AssignMarketplaceTransferDevice);
        $canReleaseTransfer = $canTransferQueue
            && $transferAuthorization->allows($actor, StockTransferPermission::Dispatch)
            && $qcAuthorization->allows($actor, QcPermission::ReleaseMarketplaceTransferDevice);
        $clock = app(BusinessTimezone::class);

        return response()->json(['data' => ['reserved_orders' => $canDispatch ? $service->queueQuery($actor)->count() : 0,
            ...$inspections->counters($actor),
            'qc_order_demand' => $canDispatch ? $demand->calculate($actor) : ['units_needed' => 0, 'orders_affected' => 0],
            'qc_transfer_demand' => ['units_needed' => $transferSummary['units_needed'], 'transfers_affected' => $transferSummary['transfers_affected']],
            'marketplace_transfers' => $transferSummary['marketplace_transfers'],
            'timezone' => $clock->name(), 'capabilities' => [
                'inspections' => '/workspace/qc/inspections',
                'queue' => '/workspace/qc/pending',
                'dispatch' => '/workspace/qc/dispatch',
                'individual_scan' => $canScan,
                'explicit_ship' => $canShip,
                'bulk_ship_limit' => $canShip ? 50 : 0,
                'marketplace_transfers' => $canTransferQueue,
                'transfer_assignment' => $canAssignTransfer,
                'transfer_release' => $canReleaseTransfer,
                'endpoints' => [
                    'transfer_queue' => '/workspace/qc/transfers',
                    'transfer_detail' => '/workspace/qc/transfers/{transfer}',
                    'transfer_verified_scan' => '/workspace/qc/transfers/{transfer}/verified-scan',
                    'transfer_release' => '/workspace/qc/transfers/{transfer}/assignments/{assignment}/release',
                ],
                'access' => ['inspections' => $canInspect, 'dispatch' => $canDispatch, 'scan' => $canScan, 'ship' => $canShip,
                    'marketplace_transfers' => $canTransferQueue, 'transfer_assignment' => $canAssignTransfer, 'transfer_release' => $canReleaseTransfer],
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
        $input = $request->validate(['code' => ['required', 'string', 'max:2048'], 'physical_serial' => ['nullable', 'string', 'min:3', 'max:100', 'regex:/\A[A-Za-z0-9._ -]+\z/'], 'order_item_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $service->verifiedScan($order, $input['code'], $input['physical_serial'] ?? null, $input['order_item_id'] ?? null, $request->user()), 'message' => 'QC unit matched and assigned. The Order remains Reserved until Mark Shipped.']);
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
