<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\InventoryPermission;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Models\StockRequest;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use App\Services\Inventory\StockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockRequestController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $service = app(StockRequestService::class);
        $query = $service->visibleQuery($request->user())->orderByDesc('id');

        return $this->page($request, $query, ['reference', 'reason'], fn (StockRequest $stockRequest) => $this->present($request, $stockRequest));
    }

    public function options(Request $request): JsonResponse
    {
        $authorization = app(InventoryAuthorization::class);
        $user = $request->user();
        $authorization->authorize($user, InventoryPermission::ViewStockRequests);
        $data = $request->validate(['q' => 'nullable|string|max:100']);

        $service = app(StockRequestService::class);
        $search = trim((string) ($data['q'] ?? ''));
        $canCreate = $authorization->allows($user, InventoryPermission::CreateStockRequests);

        return response()->json(['data' => [
            'can_create' => $canCreate,
            'purposes' => collect(StockRequestPurpose::cases())->map(fn (StockRequestPurpose $purpose) => [
                'value' => $purpose->value,
                'label' => $purpose->getLabel(),
            ])->values(),
            'inventories' => $canCreate
                ? $service->searchInventories($user, $search)->map(fn ($inventory) => [
                    'id' => $inventory->id,
                    'label' => $service->inventoryLabel($inventory),
                ])->values()
                : collect(),
            'orders' => $canCreate
                ? $service->searchOrders($user, $search)->map(fn ($order) => [
                    'id' => $order->id,
                    'label' => trim(($order->reference ?? 'Order #'.$order->id).($order->external_order_number ? ' · '.$order->external_order_number : '')),
                ])->values()
                : collect(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => 'required|in:for_order,permanent_transfer',
            'order_id' => 'nullable|integer',
            'reason' => 'required|string|max:2000',
            'idempotency_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_inventory_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $stockRequest = app(StockRequestService::class)->create(new CreateStockRequestData(
            purpose: StockRequestPurpose::from($data['purpose']),
            orderId: isset($data['order_id']) ? (int) $data['order_id'] : null,
            items: array_map(fn (array $item) => new StockRequestItemData(
                productInventoryId: (int) $item['product_inventory_id'],
                quantity: (int) $item['quantity'],
            ), $data['items']),
            reason: $data['reason'],
            idempotencyKey: $data['idempotency_key'],
        ), $request->user());

        return $this->show($request, $stockRequest);
    }

    public function show(Request $request, StockRequest $stockRequest): JsonResponse
    {
        abort_unless(app(StockRequestService::class)->canView($request->user(), $stockRequest), 403);

        return response()->json(['data' => $this->present($request, $stockRequest, true)]);
    }

    public function act(Request $request, StockRequest $stockRequest, string $action): JsonResponse
    {
        abort_unless(app(StockRequestService::class)->canView($request->user(), $stockRequest), 403);
        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
            'source_line_id' => 'nullable|integer',
            'note' => 'nullable|string|max:2000',
        ]);

        if (in_array($action, ['approve_source', 'reject_source'], true)) {
            abort_unless(isset($data['source_line_id']), 422, 'Select a stock source.');
            $line = $stockRequest->sourceLines()->whereKey((int) $data['source_line_id'])->firstOrFail();
            app(StockRequestApprovalService::class)->decide(
                $line,
                $action === 'approve_source' ? StockRequestSourceStatus::Approved : StockRequestSourceStatus::Rejected,
                $data['note'] ?? null,
                $data['idempotency_key'],
                $request->user(),
            );
        } elseif ($action === 'execute') {
            app(StockRequestExecutionService::class)->execute(
                $stockRequest,
                $data['idempotency_key'],
                $request->user(),
            );
        } else {
            abort(404);
        }

        return $this->show($request, $stockRequest->refresh());
    }

    private function present(Request $request, StockRequest $stockRequest, bool $detail = false): array
    {
        $stockRequest->loadMissing(['requester', 'order']);
        $data = [
            'id' => $stockRequest->id,
            'title' => $stockRequest->reference,
            'subtitle' => $stockRequest->purpose->getLabel(),
            'meta' => trim(($stockRequest->requester?->name ?? '').($stockRequest->order ? ' · '.$stockRequest->order->reference : '')),
            'status' => $stockRequest->status->value,
        ];

        if (! $detail) {
            return $data;
        }

        $stockRequest->load(['items.sourceLines.decidedBy', 'execution.executedBy.employee']);
        $approval = app(StockRequestApprovalService::class);
        $eligible = $approval->eligiblePendingLines($stockRequest, $request->user());
        $sourceOptions = $eligible->map(fn ($line) => [
            'value' => $line->id,
            'label' => $line->item->sku.' · '.$line->source_label.' · Qty '.$line->proposed_quantity,
        ])->values()->all();

        $actions = [];
        if ($sourceOptions !== []) {
            $actions[] = $this->action('approve_source', 'Approve stock source', [
                $this->field('source_line_id', 'Stock source', 'select', true, null, $sourceOptions),
                $this->field('note', 'Approval note', 'multiline'),
            ]);
            $actions[] = $this->action('reject_source', 'Reject stock source', [
                $this->field('source_line_id', 'Stock source', 'select', true, null, $sourceOptions),
                $this->field('note', 'Rejection reason', 'multiline', true),
            ]);
        }
        if (app(StockRequestExecutionService::class)->canExecute($request->user(), $stockRequest)) {
            $actions[] = $this->action('execute', 'Execute stock request');
        }

        return [...$data,
            'fields' => [
                'purpose' => $stockRequest->purpose->getLabel(),
                'requester' => $stockRequest->requester?->name,
                'order' => $stockRequest->order?->reference,
                'reason' => $stockRequest->reason,
            ],
            'items' => $stockRequest->items->map(fn ($item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'product' => $item->product_name,
                'warehouse' => $item->warehouse_name,
                'quantity' => $item->quantity,
                'sources' => $item->sourceLines->map(fn ($line) => $line->source_label.' · '.$line->proposed_quantity.' · '.$line->status->getLabel())->join('; '),
            ])->values(),
            'actions' => $actions,
        ];
    }
}
