<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Returns\CustomerReturnReadService;
use App\Services\Returns\CustomerReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::View);

        $query = app(CustomerReturnReadService::class)->query($request->user())->orderByDesc('id');
        if ($request->input('filter') === 'open') {
            $query->whereIn('status', [CustomerReturnStatus::Draft, CustomerReturnStatus::QcPending]);
        }

        $response = $this->page($request, $query, ['reference', 'notes'], fn ($r) => $this->present($request, $r));
        $response->setData([...$response->getData(true), 'can_create' => app(CustomerReturnAuthorization::class)->allows($request->user(), CustomerReturnPermission::Create)]);

        return $response;
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

    public function eligibleOrders(Request $request): JsonResponse
    {
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::Create);
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $query = Order::query()->where('status', OrderStatus::Fulfilled)
            ->whereHas('items.fulfillmentItem', fn ($items) => $items->whereRaw(
                'order_fulfillment_items.quantity > (SELECT COALESCE(SUM(return_items.return_quantity), 0) FROM customer_return_items return_items JOIN customer_returns return_records ON return_records.id = return_items.customer_return_id WHERE return_items.order_fulfillment_item_id = order_fulfillment_items.id AND return_records.status <> ?)',
                [CustomerReturnStatus::Cancelled->value]
            ))->orderByDesc('order_date')->orderByDesc('id');
        app(OrderResponsibilityScopeService::class)->applyOrders($query, $request->user());
        if (app(OrderResponsibilityScopeService::class)->requiresScope($request->user())) {
            $query->whereHas('items');
        }
        if (filled($data['q'] ?? null)) {
            $term = '%'.$data['q'].'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('external_order_number', 'like', $term)->orWhere('customer_name', 'like', $term));
        }

        return response()->json($query->paginate(20)->through(fn ($order) => [
            'id' => $order->id, 'title' => $order->reference ?? 'Order #'.$order->id,
            'subtitle' => $order->customer_name, 'meta' => $order->order_date?->format('Y-m-d'), 'status' => $order->status->value,
        ]));
    }

    public function options(Request $request): JsonResponse
    {
        $d = $request->validate(['order_id' => 'required|integer']);
        $order = Order::query()->with('items.fulfillmentItem', 'warehouse')->findOrFail($d['order_id']);
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View, $order);
        app(CustomerReturnAuthorization::class)->authorize($request->user(), CustomerReturnPermission::Create);
        abort_unless($order->status === OrderStatus::Fulfilled, 404);
        $eligible = $order->items->filter(fn ($i) => $i->fulfillmentItem !== null)->map(function ($item) {
            $shipped = $item->fulfillmentItem->quantity;
            $returned = CustomerReturnItem::query()->where('order_fulfillment_item_id', $item->fulfillmentItem->id)
                ->whereHas('customerReturn', fn ($query) => $query->where('status', '!=', CustomerReturnStatus::Cancelled->value))
                ->sum('return_quantity');

            return ['value' => $item->fulfillmentItem->id, 'label' => $item->product_name.' (returnable '.($shipped - $returned).')', 'remaining' => $shipped - $returned];
        })->filter(fn ($item) => $item['remaining'] > 0)->values();
        $fields = [$this->field('order_fulfillment_item_id', 'Returned item', 'select', true, null, $eligible->map(fn ($item) => ['value' => $item['value'], 'label' => $item['label']])->all()),
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
        $order = Order::query()->findOrFail($d['order_id']);
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View, $order);
        if ($retry = CustomerReturn::query()->where('idempotency_key', $d['idempotency_key'])->first()) {
            abort_unless($retry->created_by_user_id === $request->user()->id, 403);

            return $this->show($request, $retry);
        }
        abort_unless($order->status === OrderStatus::Fulfilled, 404);
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
