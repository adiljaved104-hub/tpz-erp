<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\OrderPermission;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Returns\CustomerReturnReadService;
use App\Services\Returns\CustomerReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::View);

        return $this->page($request, app(CustomerReturnReadService::class)->query($request->user())->orderByDesc('id'), ['reference', 'notes'], fn ($r) => $this->present($request, $r));
    }

    public function show(Request $request, CustomerReturn $return): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::View, $return);

        return response()->json(['data' => $this->present($request, $return, true)]);
    }

    private function present(Request $request, CustomerReturn $r, bool $detail = false): array
    {
        $data = ['id' => $r->id, 'title' => $r->reference, 'subtitle' => 'Order #'.$r->order_id, 'meta' => $r->reported_at?->format('Y-m-d'), 'status' => $r->status->value];
        if (! $detail) {
            return $data;
        }
        $auth = app(CustomerReturnAuthorization::class);
        $user = $request->user();
        $actions = [];
        if ($r->status === CustomerReturnStatus::Draft && $auth->allows($user, CustomerReturnPermission::Receive, $r)) {
            $actions[] = $this->action('receive', 'Receive return');
        }
        if ($r->status === CustomerReturnStatus::Draft && $auth->allows($user, CustomerReturnPermission::Cancel, $r)) {
            $actions[] = $this->action('cancel', 'Cancel return', [$this->field('notes', 'Reason', 'multiline', true)]);
        }
        $items = $r->items()->with('inspections')->get()->map(function ($i) use ($r, $auth, $user, &$actions) {
            if ($r->status === CustomerReturnStatus::QcPending && $auth->allows($user, CustomerReturnPermission::Inspect, $r)) {
                $actions[] = $this->action('inspect-'.$i->id, 'Inspect '.$i->product_name_snapshot, [
                    $this->field('sellable_quantity', 'Passed / sellable quantity', 'number', true, 0),
                    $this->field('damaged_quantity', 'Damaged quantity', 'number', true, 0),
                    $this->field('notes', 'Inspection remarks', 'multiline')]);
            }

            return ['id' => $i->id, 'title' => $i->product_name_snapshot, 'sku' => $i->sku_snapshot, 'quantity' => $i->return_quantity,
                'return_reason' => $i->return_reason->value, 'inspected_quantity' => $i->inspections->sum('quantity')];
        });

        return [...$data, 'fields' => ['notes' => $r->notes, 'received_at' => $r->received_at?->toIso8601String(),
            'receiving_location' => $r->receivingWarehouse?->name], 'items' => $items, 'actions' => $actions];
    }

    public function options(Request $request): JsonResponse
    {
        $d = $request->validate(['order_id' => 'required|integer']);
        $order = Order::query()->with('items.fulfillmentItem', 'warehouse')->findOrFail($d['order_id']);
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View, $order);
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::Create);
        $fields = [$this->field('order_fulfillment_item_id', 'Returned item', 'select', true, null, $order->items->filter(fn ($i) => $i->fulfillmentItem !== null)->map(fn ($i) => ['value' => $i->fulfillmentItem->id, 'label' => $i->product_name.' (shipped '.$i->ordered_quantity.')'])->values()->all()),
            $this->field('quantity', 'Return quantity', 'number', true, 1),
            $this->field('return_reason', 'Reason', 'select', true, null, array_map(fn ($r) => ['value' => $r->value, 'label' => $r->label()], CustomerReturnReason::cases())),
            $this->field('notes', 'Remarks', 'multiline')];
        if ($order->warehouse->location_type !== InventoryLocationType::MarketplaceFulfilment) {
            $fields[] = $this->field('receiving_warehouse_id', 'Receiving location', 'select', true, null,
                Warehouse::query()->where('status', true)->whereNotIn('location_type', [InventoryLocationType::Transit->value, InventoryLocationType::MarketplaceFulfilment->value])->get(['id', 'name'])->map(fn ($w) => ['value' => $w->id, 'label' => $w->name])->all());
        }

        return response()->json(['data' => ['fields' => $fields]]);
    }

    public function store(Request $request): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::Create);
        $d = $request->validate(['order_id' => 'required|integer', 'receiving_warehouse_id' => 'nullable|integer', 'order_fulfillment_item_id' => 'required|integer',
            'quantity' => 'required|integer|min:1', 'return_reason' => 'required|string|max:60', 'notes' => 'nullable|string|max:5000', 'idempotency_key' => 'required|uuid']);
        if ($retry = CustomerReturn::query()->where('idempotency_key', $d['idempotency_key'])->first()) {
            abort_unless($retry->created_by_user_id === $request->user()->id, 403);

            return $this->show($request, $retry);
        }
        $r = app(CustomerReturnService::class)->create(new CreateCustomerReturnData($d['order_id'], $d['receiving_warehouse_id'] ?? null,
            [['order_fulfillment_item_id' => $d['order_fulfillment_item_id'], 'quantity' => $d['quantity'], 'return_reason' => $d['return_reason']]],
            $d['idempotency_key'], $d['notes'] ?? null), $request->user());

        return $this->show($request, $r);
    }

    public function act(Request $request, CustomerReturn $return, string $action): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::View, $return);
        $d = $request->validate(['notes' => 'nullable|string|max:5000', 'idempotency_key' => 'required|uuid',
            'sellable_quantity' => 'nullable|integer|min:0', 'damaged_quantity' => 'nullable|integer|min:0']);
        $service = app(CustomerReturnService::class);
        $user = $request->user();
        if (preg_match('/^inspect-(\\d+)$/', $action, $match)) {
            $result = $service->inspect($return->items()->findOrFail((int) $match[1]),
                new InspectCustomerReturnItemData($d['sellable_quantity'] ?? 0, $d['damaged_quantity'] ?? 0, $d['idempotency_key'], $d['notes'] ?? null), $user);
        } else {
            $result = match ($action) {
                'receive' => $service->receive($return, $user),'cancel' => $service->cancel($return, $d['notes'] ?? '', $user),default => abort(404)
            };
        }

        return $this->show($request, $result);
    }
}
