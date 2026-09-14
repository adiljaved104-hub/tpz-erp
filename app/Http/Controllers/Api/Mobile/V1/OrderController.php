<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\Orders\CancelOrderData;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\CustomerReturnPermission;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResponsibilityAssignment;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View);
        $query = Order::query()->with('platform')->orderByDesc('order_date')->orderByDesc('id');
        app(OrderResponsibilityScopeService::class)->applyOrders($query, $request->user());
        if (app(OrderResponsibilityScopeService::class)->requiresScope($request->user())) {
            $query->whereHas('items');
        }

        $filters = $request->validate(['sale_type' => 'nullable|in:standard,web', 'period' => 'nullable|in:today,week,month', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        if (($filters['sale_type'] ?? null) === 'web') {
            $query->whereNotNull('web_sales_channel');
        }
        if (($filters['sale_type'] ?? null) === 'standard') {
            $query->whereNull('web_sales_channel');
        }
        if (isset($filters['period'])) {
            $start = match ($filters['period']) {
                'week' => now()->startOfWeek(), 'month' => now()->startOfMonth(), default => now()->startOfDay()
            };
            $query->whereDate('order_date', '>=', $start->toDateString())->whereDate('order_date', '<=', now()->toDateString());
        }
        if (isset($filters['from'])) {
            $query->whereDate('order_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate('order_date', '<=', $filters['to']);
        }

        return $this->page($request, $query, ['reference', 'external_order_number', 'customer_name'], fn ($o) => $this->present($request, $o));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View, $order);

        return response()->json(['data' => $this->present($request, $order, true)]);
    }

    private function present(Request $request, Order $order, bool $detail = false): array
    {
        $auth = app(OrderAuthorization::class);
        $user = $request->user();
        $price = $auth->allows($user, OrderPermission::ViewSellingPrice, $order);
        $data = ['id' => $order->id, 'title' => $order->reference ?? 'Order #'.$order->id, 'subtitle' => $order->platform?->name,
            'meta' => $order->order_date?->format('Y-m-d').($price ? ' · AED '.$order->grand_total : ''), 'status' => $order->status->value];
        if (! $detail) {
            return $data;
        }
        $order->load(['items.fulfillmentItem', 'warehouse', 'platform']);
        $fields = $order->only(['external_order_number', 'customer_name', 'customer_phone', 'courier_name', 'tracking_number', 'notes', 'warehouse_id', 'marketplace_platform_id']);
        $fields['order_date'] = $order->order_date?->format('Y-m-d');
        $fields['warehouse'] = $order->warehouse?->name;
        $fields['web_sales_channel'] = $order->web_sales_channel?->value;
        $fields['delivery_type'] = $order->delivery_type?->value;
        if ($price) {
            $fields['grand_total'] = $order->grand_total;
        }
        $actions = [];
        if ($order->status === OrderStatus::Draft && $auth->allows($user, OrderPermission::UpdateDraft, $order) && $auth->allows($user, OrderPermission::EditSellingPrice, $order)) {
            $actions[] = $this->action('edit', 'Edit draft');
        }
        if ($order->status === OrderStatus::Draft && $auth->allows($user, OrderPermission::Reserve, $order)) {
            $actions[] = $this->action('reserve', 'Reserve stock');
        }
        if ($order->status === OrderStatus::Reserved && $auth->allows($user, OrderPermission::Fulfill, $order)) {
            $actions[] = $this->action('fulfill', 'Confirm shipment');
        }
        if (in_array($order->status, [OrderStatus::Draft, OrderStatus::Reserved], true) && $auth->allows($user, OrderPermission::Cancel, $order)) {
            $actions[] = $this->action('cancel', 'Cancel / release reservation', [$this->field('reason', 'Cancellation reason', 'multiline', true)]);
        }
        if ($order->status === OrderStatus::Fulfilled && app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::Create)) {
            $actions[] = $this->action('return', 'Initiate return');
        }

        return [...$data, 'fields' => $fields, 'items' => $order->items->map(fn ($i) => [
            'id' => $i->id, 'product_id' => $i->product_id, 'title' => $i->product_name, 'sku' => $i->sku, 'quantity' => $i->ordered_quantity,
            'fulfillment_item_id' => $i->fulfillmentItem?->id,
            ...($price ? $i->only(['selling_price', 'discount_total', 'vat_rate', 'line_total']) : []),
        ]), 'actions' => $actions];
    }

    public function options(Request $request): JsonResponse
    {
        $auth = app(OrderAuthorization::class);
        $user = $request->user();
        $auth->authorize($user, OrderPermission::View);
        $input = $request->validate(['marketplace_platform_id' => 'nullable|integer', 'warehouse_id' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $platform = $input['marketplace_platform_id'] ?? null;
        $warehouse = $input['warehouse_id'] ?? null;
        $products = Product::query()->products()->active();
        if ($warehouse) {
            app(OrderResponsibilityScopeService::class)->applyProducts($products, $user, $platform, $warehouse);
        } else {
            $products->whereRaw('1=0');
        }
        if (filled($input['q'] ?? null)) {
            $products->where(fn ($q) => $q->where('name', 'like', '%'.$input['q'].'%')->orWhere('sku', 'like', '%'.$input['q'].'%'));
        }
        $platforms = MarketplacePlatform::query()->where('status', true)->get(['id', 'name']);
        if (app(OrderResponsibilityScopeService::class)->requiresScope($user)) {
            $assignments = ResponsibilityAssignment::query()->active()->where('employee_id', $user->employee->id);
            if (! (clone $assignments)->whereDoesntHave('platformScope')->exists()) {
                $platforms = $platforms->whereIn('id', (clone $assignments)->with('platformScope')->get()->pluck('platformScope.marketplace_platform_id'))->values();
            }
        }

        return response()->json(['data' => [
            'can_create' => $auth->allows($user, OrderPermission::Create) && $auth->allows($user, OrderPermission::EditSellingPrice),
            'can_reserve' => $auth->allows($user, OrderPermission::Reserve),
            'platforms' => $platforms, 'warehouses' => collect(app(OrderFulfillmentLocationService::class)->options($platform))->map(fn ($label, $id) => ['id' => $id, 'name' => $label])->values(),
            'products' => $products->orderBy('name')->paginate(25)->through(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku]),
        ]]);
    }

    private function dto(Request $request): SaveAndReserveOrderData
    {
        $d = $request->validate([
            'warehouse_id' => 'required|integer', 'marketplace_platform_id' => 'nullable|integer', 'external_order_number' => 'nullable|string|max:255',
            'order_date' => 'required|date_format:Y-m-d', 'notes' => 'nullable|string|max:5000', 'idempotency_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:100', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1',
            'items.*.selling_price' => 'required|numeric|min:0', 'items.*.discount_total' => 'nullable|numeric|min:0', 'items.*.vat_rate' => 'nullable|numeric|min:0',
            'web_sales_channel' => 'nullable|in:website,whatsapp,walk_in,other', 'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:40', 'delivery_type' => 'nullable|in:courier,shop_pickup',
            'courier_name' => 'nullable|string|max:100', 'tracking_number' => 'nullable|string|max:100',
        ]);

        return new SaveAndReserveOrderData(warehouseId: $d['warehouse_id'], platformId: $d['marketplace_platform_id'] ?? null,
            externalOrderNumber: $d['external_order_number'] ?? null, orderDate: $d['order_date'], handledByEmployeeId: $request->user()->employee->id,
            notes: $d['notes'] ?? null, items: array_map(fn ($i) => new OrderItemData($i['product_id'], $i['quantity'], (string) $i['selling_price'], (string) ($i['discount_total'] ?? '0.00'), (string) ($i['vat_rate'] ?? '0.0000')), $d['items']),
            idempotencyKey: $d['idempotency_key'], webSalesChannel: $d['web_sales_channel'] ?? null, customerName: $d['customer_name'] ?? null,
            customerPhone: $d['customer_phone'] ?? null, deliveryType: $d['delivery_type'] ?? null, courierName: $d['courier_name'] ?? null, trackingNumber: $d['tracking_number'] ?? null);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['mode' => 'required|in:draft,reserve']);
        $auth = app(OrderAuthorization::class);
        $user = $request->user();
        $auth->authorize($user, OrderPermission::Create);
        $auth->authorize($user, OrderPermission::EditSellingPrice);
        $dto = $this->dto($request);
        // Scope retries before the ERP service's global idempotency lookup.
        if ($retry = Order::query()->where('idempotency_key', $dto->idempotencyKey)->first()) {
            abort_unless($retry->created_by_user_id === $user->id, 403);

            return $this->show($request, $retry);
        }
        $service = app(OrderService::class);
        $order = $request->input('mode') === 'reserve' ? $service->saveAndReserve($dto, $user) : $service->saveDraft($dto, $user);

        return $this->show($request, $order);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::UpdateDraft, $order);

        return $this->show($request, app(OrderService::class)->saveDraft($this->dto($request), $request->user(), $order));
    }

    public function act(Request $request, Order $order, string $action): JsonResponse
    {
        app(OrderAuthorization::class)->authorize($request->user(), OrderPermission::View, $order);
        $data = $request->validate(['idempotency_key' => 'required|uuid', 'reason' => 'nullable|string|max:2000']);
        $service = app(OrderService::class);
        $user = $request->user();
        $result = match ($action) {
            'reserve' => $service->reserveDraft($order, $user),
            'fulfill' => $service->fulfill($order, $data['idempotency_key'], $user),
            'cancel' => $service->cancel($order, new CancelOrderData($data['reason'] ?? '', $data['idempotency_key']), $user),
            default => abort(404),
        };

        return $this->show($request, $result);
    }
}
