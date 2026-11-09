<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\Inventory\InventoryAdjustmentData;
use App\Enums\InventoryPermission;
use App\Models\InventoryAdjustment;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\InventoryAdjustmentOptionService;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryAdjustmentController extends MobileController
{
    private const TYPES = ['saleable_increase', 'saleable_decrease', 'mark_damaged', 'restore_damaged'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request->user());
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->adjustmentQuery($request->user())
            ->with(['product:id,sku,name', 'warehouse:id,name', 'performedBy:id,name'])
            ->when(filled($data['q'] ?? null), function (Builder $query) use ($data): void {
                $term = '%'.trim($data['q']).'%';
                $query->where(fn (Builder $scope) => $scope->where('reference', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhereHas('product', fn (Builder $product) => $product->where('sku', 'like', $term)->orWhere('name', 'like', $term)));
            })
            ->when($data['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('performed_at', '>=', $date))
            ->when($data['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('performed_at', '<=', $date))
            ->orderByDesc('performed_at')->orderByDesc('id');

        $page = $query->paginate($data['per_page'] ?? 25)->through(
            fn (InventoryAdjustment $adjustment): array => $this->present($request->user(), $adjustment),
        );

        return response()->json([...$page->toArray(),
            'can_create' => app(InventoryAuthorization::class)->allows($request->user(), InventoryPermission::AdjustStock),
        ]);
    }

    public function show(Request $request, int $adjustment): JsonResponse
    {
        $this->authorizeView($request->user());
        $adjustment = $this->adjustmentQuery($request->user())
            ->with(['product:id,sku,name', 'warehouse:id,name', 'performedBy:id,name', 'receipt:id,reference', 'movements'])
            ->findOrFail($adjustment);

        return response()->json(['data' => $this->present($request->user(), $adjustment, true)]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorizeView($request->user());
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = app(InventoryReadService::class)->adjustmentGridInventories($request->user())
            ->with(['product.brandRelation:id,name', 'warehouse:id,name,code'])
            ->when(filled($data['q'] ?? null), function (Builder $query) use ($data): void {
                $term = '%'.trim($data['q']).'%';
                $query->where(fn (Builder $scope) => $scope
                    ->whereHas('product', fn (Builder $product) => $product->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                    ->orWhereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('name', 'like', $term)->orWhere('code', 'like', $term)));
            })->orderBy('product_id')->orderBy('warehouse_id');
        $service = app(InventoryAdjustmentOptionService::class);
        $page = $query->paginate($data['per_page'] ?? 25)->through(fn (ProductInventory $inventory): array => [
            'id' => $inventory->id,
            'title' => $inventory->product->sku.' · '.$inventory->product->name,
            'subtitle' => $inventory->warehouse->code.' · '.$inventory->warehouse->name,
            'meta' => 'Saleable '.$inventory->sellableQuantity().' · Reserved '.$inventory->reserved_quantity.' · Damaged '.$inventory->damaged_quantity,
            'status' => $service->canAdjust($request->user(), $inventory) ? 'adjustable' : 'read_only',
            'available_quantity' => $inventory->available_quantity,
            'reserved_quantity' => $inventory->reserved_quantity,
            'damaged_quantity' => $inventory->damaged_quantity,
            'can_adjust' => $service->canAdjust($request->user(), $inventory),
        ]);

        return response()->json([...$page->toArray(),
            'types' => collect(self::TYPES)->map(fn (string $type): array => ['value' => $type, 'label' => str($type)->replace('_', ' ')->headline()->toString()])->all(),
        ]);
    }

    public function inventoryOptions(Request $request, int $inventory): JsonResponse
    {
        $this->authorizeView($request->user());
        $data = $request->validate(['type' => ['required', Rule::in(self::TYPES)]]);
        $inventory = $this->inventoryQuery($request->user())->findOrFail($inventory);
        $service = app(InventoryAdjustmentOptionService::class);
        abort_unless($service->canAdjust($request->user(), $inventory), 403);
        $holders = $service->holderOptions($request->user(), $inventory, $data['type']);

        return response()->json(['data' => [
            'holders' => collect($holders)->map(fn (string $name, int|string $id): array => ['value' => (int) $id, 'label' => $name])->values(),
            'holder_required' => $service->shouldSelectHolder($request->user(), $inventory, $data['type']),
            'can_enter_valuation' => app(InventoryAuthorization::class)->allows($request->user(), InventoryPermission::ViewFinancials, $inventory),
            'available_quantity' => $inventory->available_quantity,
            'reserved_quantity' => $inventory->reserved_quantity,
            'sellable_quantity' => $inventory->sellableQuantity(),
            'damaged_quantity' => $inventory->damaged_quantity,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        app(InventoryAuthorization::class)->authorize($request->user(), InventoryPermission::AdjustStock);
        $data = $request->validate([
            'inventory_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(self::TYPES)],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'allocation_account_id' => ['nullable', 'integer'],
            'valuation_unit_cost' => ['nullable', 'regex:/^\d{1,11}(?:\.\d{1,4})?$/'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $inventory = $this->inventoryQuery($request->user())->findOrFail($data['inventory_id']);
        abort_unless(app(InventoryAdjustmentOptionService::class)->canAdjust($request->user(), $inventory), 403);
        if (array_key_exists('valuation_unit_cost', $data) && $data['valuation_unit_cost'] !== null) {
            app(InventoryAuthorization::class)->authorize($request->user(), InventoryPermission::ViewFinancials, $inventory);
        }
        $adjustment = app(InventoryAdjustmentService::class)->post(new InventoryAdjustmentData(
            inventoryId: $inventory->id,
            type: $data['type'],
            quantity: $data['quantity'],
            reason: $data['reason'],
            idempotencyKey: $data['idempotency_key'],
            allocationAccountId: $data['allocation_account_id'] ?? null,
            isNewPurchase: $data['type'] === 'saleable_increase' ? false : null,
            valuationUnitCost: $data['valuation_unit_cost'] ?? null,
        ), $request->user());

        return $this->show($request, $adjustment->id);
    }

    private function adjustmentQuery(User $user): Builder
    {
        return InventoryAdjustment::query()->whereIn('product_inventory_id', $this->inventoryQuery($user)->select('product_inventories.id'));
    }

    private function inventoryQuery(User $user): Builder
    {
        return app(InventoryReadService::class)->adjustmentGridInventories($user);
    }

    private function authorizeView(User $user): void
    {
        app(InventoryAuthorization::class)->authorize($user, InventoryPermission::View);
    }

    private function present(User $user, InventoryAdjustment $adjustment, bool $detail = false): array
    {
        $data = [
            'id' => $adjustment->id,
            'title' => $adjustment->reference,
            'subtitle' => collect([$adjustment->product?->sku, $adjustment->product?->name])->filter()->implode(' · '),
            'meta' => collect([$adjustment->warehouse?->name, $adjustment->performed_at?->toIso8601String()])->filter()->implode(' · '),
            'status' => $adjustment->type,
        ];
        if (! $detail) {
            return $data;
        }
        $fields = [
            'type' => str($adjustment->type)->replace('_', ' ')->headline()->toString(),
            'product' => $adjustment->product?->name,
            'sku' => $adjustment->product?->sku,
            'warehouse' => $adjustment->warehouse?->name,
            'available_before' => $adjustment->available_before,
            'available_delta' => $adjustment->available_delta,
            'available_after' => $adjustment->available_after,
            'damaged_before' => $adjustment->damaged_before,
            'damaged_delta' => $adjustment->damaged_delta,
            'damaged_after' => $adjustment->damaged_after,
            'reason' => $adjustment->reason,
            'performed_by' => $adjustment->performedBy?->name,
            'performed_at' => $adjustment->performed_at?->toIso8601String(),
            'grn_reference' => $adjustment->grn_reference,
            'movement_reference' => $adjustment->movement_reference,
        ];
        $financial = app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials, $adjustment->inventory);
        if ($financial) {
            $fields['reference_unit_cost'] = $adjustment->reference_unit_cost;
        }
        $movements = [];
        if (app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewMovements, $adjustment->inventory)) {
            $movements = $adjustment->movements->map(function ($movement) use ($financial): array {
                $row = [
                    'id' => $movement->id,
                    'reference' => $movement->reference,
                    'movement_type' => $movement->movement_type->value,
                    'quantity' => $movement->quantity,
                    'available_delta' => $movement->available_delta,
                    'reserved_delta' => $movement->reserved_delta,
                    'damaged_delta' => $movement->damaged_delta,
                    'reason' => $movement->reason,
                    'occurred_at' => $movement->occurred_at?->toIso8601String(),
                ];
                if ($financial) {
                    $row['unit_cost'] = $movement->unit_cost;
                }

                return $row;
            })->values();
        }

        return [...$data, 'fields' => $fields, 'field_schema' => $this->readFields($fields), 'items' => $movements, 'actions' => []];
    }
}
