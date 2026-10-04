<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\StockTransfers\CancelStockTransfer;
use App\Actions\StockTransfers\CreateStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\ReturnStockTransferToSource;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Enums\InventoryLocationType;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\StockTransfers\StockTransferReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(StockTransferAuthorization::class)->authorize($request->user(), StockTransferPermission::View);

        return $this->page(
            $request,
            app(StockTransferReadService::class)->query($request->user())
                ->orderByDesc('transfer_date')->orderByDesc('id'),
            ['reference'],
            fn (StockTransfer $transfer): array => $this->present($request, $transfer),
        );
    }

    public function show(Request $request, int $transfer): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->readable($request, $transfer), true)]);
    }

    public function options(Request $request): JsonResponse
    {
        app(StockTransferAuthorization::class)->authorize($request->user(), StockTransferPermission::Create);
        $input = $request->validate(['q' => 'nullable|string|max:100']);
        $products = DB::table('products')
            ->where('inventory_item_type', 'product')
            ->where('status', 'active')
            ->select(['products.id', 'products.name', 'products.sku']);
        app(ResponsibilityProductScopeService::class)->apply($products, 'products.id', $request->user());
        if (filled($input['q'] ?? null)) {
            $term = '%'.$input['q'].'%';
            $products->where(fn ($query) => $query->where('products.name', 'like', $term)
                ->orWhere('products.sku', 'like', $term));
        }

        return response()->json(['data' => [
            'locations' => Warehouse::query()->where('status', true)
                ->where('location_type', '<>', InventoryLocationType::Transit->value)
                ->orderBy('name')->get(['id', 'code', 'name', 'location_type']),
            'products' => $products->orderBy('products.name')->limit(50)->get(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        app(StockTransferAuthorization::class)->authorize($user, StockTransferPermission::Create);
        $data = $request->validate([
            'source_warehouse_id' => 'required|integer',
            'destination_warehouse_id' => 'required|integer|different:source_warehouse_id',
            'transfer_date' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:5000',
            'idempotency_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1',
        ]);
        if ($existing = StockTransfer::query()->where('idempotency_key', $data['idempotency_key'])->first()) {
            abort_unless($existing->created_by_user_id === $user->id, 403);
            app(StockTransferAuthorization::class)->authorize($user, StockTransferPermission::View, $existing);

            return $this->show($request, $existing->id);
        }
        $items = array_map(
            fn (array $item): StockTransferItemData => new StockTransferItemData($item['product_id'], $item['quantity']),
            $data['items'],
        );
        $transfer = app(CreateStockTransfer::class)->handle(new CreateStockTransferData(
            $data['source_warehouse_id'],
            $data['destination_warehouse_id'],
            $data['transfer_date'],
            $items,
            $data['idempotency_key'],
            $user->employee?->id,
            $data['notes'] ?? null,
        ), $user);

        return $this->show($request, $transfer->id);
    }

    public function act(Request $request, int $transfer, string $action): JsonResponse
    {
        $transfer = $this->readable($request, $transfer);
        $data = $request->validate([
            'idempotency_key' => 'required|uuid',
            'reason' => in_array($action, ['cancel', 'return'], true)
                ? 'required|string|max:2000'
                : 'nullable|string|max:2000',
        ]);

        try {
            match ($action) {
                'dispatch' => app(DispatchStockTransfer::class)->handle($transfer, $data['idempotency_key'], $request->user()),
                'receive' => app(ReceiveStockTransfer::class)->handle($transfer, $data['idempotency_key'], $request->user()),
                'cancel' => app(CancelStockTransfer::class)->handle($transfer, $data['reason'], $data['idempotency_key'], $request->user()),
                'return' => app(ReturnStockTransferToSource::class)->handle($transfer, $data['reason'], $data['idempotency_key'], $request->user()),
                default => abort(404),
            };
        } catch (InvalidStockTransferTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->show($request, $transfer->id);
    }

    private function readable(Request $request, int $id): StockTransfer
    {
        $transfer = StockTransfer::query()->with([
            'sourceWarehouse:id,name,code,location_type,marketplace_platform_id,status',
            'destinationWarehouse:id,name,code,location_type,marketplace_platform_id,status',
            'handledBy:id,name,employee_id',
            'statusEvents.actor:id,name',
        ])->findOrFail($id);
        $authorization = app(StockTransferAuthorization::class);
        $authorization->authorize($request->user(), StockTransferPermission::View, $transfer);
        $transfer->load($authorization->allows($request->user(), StockTransferPermission::ViewCost, $transfer)
            ? 'costedDisplayItems'
            : 'displayItems');

        return $transfer;
    }

    private function present(Request $request, StockTransfer $transfer, bool $detail = false): array
    {
        $data = [
            'id' => $transfer->id,
            'title' => $transfer->reference,
            'subtitle' => $transfer->sourceWarehouse?->name.' → '.$transfer->destinationWarehouse?->name,
            'meta' => $transfer->transfer_date?->format('Y-m-d'),
            'status' => $transfer->status->value,
        ];
        if (! $detail) {
            return $data;
        }
        $authorization = app(StockTransferAuthorization::class);
        $user = $request->user();
        $actions = [];
        if ($transfer->status === StockTransferStatus::Draft
            && $authorization->allows($user, StockTransferPermission::Dispatch, $transfer)) {
            $actions[] = $this->action('dispatch', 'Dispatch transfer');
        }
        if ($transfer->status === StockTransferStatus::Draft
            && $authorization->allows($user, StockTransferPermission::Cancel, $transfer)) {
            $actions[] = $this->action('cancel', 'Cancel transfer', [
                $this->field('reason', 'Cancellation reason', 'multiline', true),
            ]);
        }
        if ($transfer->status === StockTransferStatus::Dispatched
            && $authorization->allows($user, StockTransferPermission::Receive, $transfer)) {
            $actions[] = $this->action('receive', 'Receive transfer');
        }
        if ($transfer->status === StockTransferStatus::Dispatched
            && $authorization->allows($user, StockTransferPermission::Cancel, $transfer)) {
            $actions[] = $this->action('return', 'Return to source', [
                $this->field('reason', 'Return reason', 'multiline', true),
            ]);
        }
        $cost = $authorization->allows($user, StockTransferPermission::ViewCost, $transfer);

        $items = $cost ? $transfer->costedDisplayItems : $transfer->displayItems;

        return [...$data, 'fields' => [
            'source_location' => $transfer->sourceWarehouse?->name,
            'destination_location' => $transfer->destinationWarehouse?->name,
            'transfer_date' => $transfer->transfer_date?->format('Y-m-d'),
            'handled_by' => $transfer->handledBy?->name,
            'notes' => $transfer->notes,
            'dispatched_at' => $transfer->dispatched_at?->format('Y-m-d H:i'),
            'received_at' => $transfer->received_at?->format('Y-m-d H:i'),
            'cancelled_at' => $transfer->cancelled_at?->format('Y-m-d H:i'),
            'returned_at' => $transfer->returned_at?->format('Y-m-d H:i'),
        ], 'items' => $items->map(fn ($item): array => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'title' => $item->product_name,
            'sku' => $item->sku,
            'quantity' => $item->quantity,
            'dispatched_quantity' => $item->dispatched_quantity,
            'received_quantity' => $item->received_quantity,
            'returned_quantity' => $item->returned_quantity,
            ...($cost ? ['dispatch_unit_cost' => $item->dispatch_unit_cost] : []),
        ]), 'history' => $transfer->statusEvents->map(fn ($event): array => [
            'id' => $event->id,
            'title' => $event->to_status->value,
            'meta' => $event->actor?->name,
            'created_at' => $event->created_at?->format('Y-m-d H:i'),
        ]), 'actions' => $actions];
    }
}
