<?php

namespace App\Services\Inventory;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestStatus;
use App\Models\InventoryAllocationAccount;
use App\Models\InventoryAllocationBalance;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\StockRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Notifications\StockRequestNotificationDispatcher;
use App\Services\Orders\OrderReadService;
use App\Services\ReferenceSequenceService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockRequestService
{
    public function __construct(
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly OrderAuthorization $orderAuthorization,
        private readonly OrderReadService $orders,
        private readonly ResponsibilityProductScopeService $responsibilities,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly StockRequestNotificationDispatcher $notifications,
    ) {}

    public function create(CreateStockRequestData $data, User $actor): StockRequest
    {
        $this->inventoryAuthorization->authorize($actor, InventoryPermission::CreateStockRequests);
        $validated = Validator::make([
            'purpose' => $data->purpose->value,
            'order_id' => $data->orderId,
            'reason' => trim($data->reason),
            'idempotency_key' => $data->idempotencyKey,
            'items' => collect($data->items)->map(fn ($item): array => [
                'product_inventory_id' => $item->productInventoryId,
                'quantity' => $item->quantity,
            ])->all(),
        ], [
            'purpose' => ['required', Rule::enum(StockRequestPurpose::class)],
            'order_id' => [
                Rule::requiredIf($data->purpose === StockRequestPurpose::ForOrder),
                Rule::prohibitedIf($data->purpose !== StockRequestPurpose::ForOrder),
                'nullable', 'integer', 'exists:orders,id',
            ],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_inventory_id' => ['required', 'integer', 'distinct', 'exists:product_inventories,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'order_id.required' => 'Please select the Order this stock is required for.',
            'order_id.prohibited' => 'An Order can only be selected when the purpose is For Order.',
            'reason.required' => 'Please provide a reason before continuing.',
            'reason.min' => 'Please provide a reason of at least 5 characters.',
            'items.required' => 'Please select at least one product.',
            'items.min' => 'Please select at least one product.',
            'items.max' => 'Select no more than 100 products in one request.',
            'items.*.product_inventory_id.required' => 'Please select a product for this row.',
            'items.*.product_inventory_id.distinct' => 'Each product and warehouse may appear only once.',
            'items.*.quantity.required' => 'Enter a quantity for this product.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
        ])->validate();

        if ($existing = StockRequest::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }

        $employee = $actor->employee;
        if ($employee === null || ! $employee->status) {
            throw ValidationException::withMessages(['requester' => 'An active Employee login is required to create a Stock Request.']);
        }

        $order = $validated['order_id'] === null ? null : Order::query()->findOrFail($validated['order_id']);
        if ($order !== null) {
            $this->orderAuthorization->authorize($actor, OrderPermission::View, $order);
        }

        $inventoryIds = collect($validated['items'])->pluck('product_inventory_id')->map(fn ($id): int => (int) $id)->sort()->values();
        $inventories = ProductInventory::query()->with(['product', 'warehouse'])->whereKey($inventoryIds)->get()->keyBy('id');
        foreach ($validated['items'] as $index => $item) {
            $inventory = $inventories->get($item['product_inventory_id']);
            if (! $inventory instanceof ProductInventory) {
                throw ValidationException::withMessages(["items.{$index}.product_inventory_id" => 'The selected inventory is no longer available.']);
            }
            $this->inventoryAuthorization->authorize($actor, InventoryPermission::CreateStockRequests, $inventory);
        }

        $reference = $this->references->nextStockRequestReference();

        $request = DB::transaction(function () use ($validated, $actor, $employee, $reference, $inventoryIds): StockRequest {
            $inventories = ProductInventory::query()
                ->with(['product', 'warehouse'])
                ->whereKey($inventoryIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $request = StockRequest::query()->create([
                'reference' => $reference,
                'purpose' => $validated['purpose'],
                'status' => StockRequestStatus::Pending,
                'order_id' => $validated['order_id'],
                'requested_by_employee_id' => $employee->id,
                'created_by_user_id' => $actor->id,
                'reason' => $validated['reason'],
                'idempotency_key' => $validated['idempotency_key'],
            ]);

            foreach ($validated['items'] as $index => $item) {
                $inventory = $inventories->get($item['product_inventory_id']);
                $availability = $this->sourceAvailability((int) $inventory->id, true);
                $requested = (int) $item['quantity'];
                if ($requested > $availability['requestable_available']) {
                    $available = $availability['requestable_available'];
                    $units = $available === 1 ? 'unit is' : 'units are';
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "{$inventory->product->sku} — Only {$available} {$units} currently available across Employee/Team and System / Unassigned sources.",
                    ]);
                }

                $remaining = $requested;
                $proposed = $availability['holders']->map(function (array $holder) use (&$remaining): array {
                    $quantity = min($remaining, $holder['available_quantity']);
                    $remaining -= $quantity;

                    return $holder + ['proposed_quantity' => $quantity];
                })->filter(fn (array $holder): bool => $holder['proposed_quantity'] > 0)->values()->all();

                $requestItem = $request->items()->create([
                    'product_inventory_id' => $inventory->id,
                    'sku' => $inventory->product->sku,
                    'product_name' => $inventory->product->name,
                    'warehouse_name' => $inventory->warehouse->name,
                    'quantity' => $requested,
                    'proposed_sources' => $proposed,
                    'system_unassigned_quantity' => $availability['system_unassigned'],
                ]);

                foreach ($proposed as $source) {
                    $requestItem->sourceLines()->create([
                        'stock_request_id' => $request->id,
                        'inventory_allocation_account_id' => $source['account_id'],
                        'source_type' => $source['type'],
                        'source_label' => $source['label'],
                        'proposed_quantity' => $source['proposed_quantity'],
                    ]);
                }

                if ($remaining > 0) {
                    $requestItem->sourceLines()->create([
                        'stock_request_id' => $request->id,
                        'inventory_allocation_account_id' => $availability['system_account_id'],
                        'source_type' => 'system',
                        'source_label' => 'System / Unassigned Stock',
                        'proposed_quantity' => $remaining,
                    ]);
                }
            }

            $this->activity->log('stock_request.created', $actor, $request, [
                'request_reference' => $request->reference,
                'purpose' => $request->purpose->value,
                'order_id' => $request->order_id,
                'requested_by_employee_id' => $employee->id,
                'product_inventory_ids' => $inventoryIds->all(),
                'reason_recorded' => true,
            ]);

            return $request->load(['items.sourceLines', 'requester', 'order']);
        }, 5);
        $this->notifications->created($request);

        return $request;
    }

    public function visibleQuery(User $actor): Builder
    {
        $this->inventoryAuthorization->authorize($actor, InventoryPermission::ViewStockRequests);
        $query = StockRequest::query()->with(['requester', 'order'])->withCount('items');
        if (! $this->responsibilities->requiresScope($actor)) {
            return $query;
        }

        $employeeId = $actor->employee?->id;
        $visibleInventoryIds = $this->responsibilities->inventoryIds($actor);

        return $query->where(function (Builder $scope) use ($employeeId, $visibleInventoryIds): void {
            $scope->where('requested_by_employee_id', $employeeId)
                ->orWhereHas('sourceLines.account', fn (Builder $accounts): Builder => $accounts->where('employee_id', $employeeId))
                ->orWhereDoesntHave('items', fn (Builder $items): Builder => $items->whereNotIn('product_inventory_id', $visibleInventoryIds));
        });
    }

    public function canView(User $actor, StockRequest $request): bool
    {
        if (! $this->inventoryAuthorization->allows($actor, InventoryPermission::ViewStockRequests)) {
            return false;
        }
        if (! $this->responsibilities->requiresScope($actor) || $request->requested_by_employee_id === $actor->employee?->id) {
            return true;
        }

        if ($request->sourceLines()->whereHas('account', fn (Builder $accounts): Builder => $accounts->where('employee_id', $actor->employee?->id))->exists()) {
            return true;
        }

        return $request->items()->pluck('product_inventory_id')
            ->every(fn (int $inventoryId): bool => $this->responsibilities->canAccessInventory($actor, $inventoryId));
    }

    /** @return Collection<int, ProductInventory> */
    public function searchInventories(User $actor, string $search): Collection
    {
        $this->inventoryAuthorization->authorize($actor, InventoryPermission::CreateStockRequests);
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        $query = ProductInventory::query()->with(['product.brandRelation', 'warehouse'])->whereHas('product', function (Builder $product) use ($search): void {
            $product->where('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhereHas('brandRelation', fn (Builder $brand): Builder => $brand->where('name', 'like', "%{$search}%"));
        });
        if ($this->responsibilities->requiresScope($actor)) {
            $query->whereIn('id', $this->responsibilities->inventoryIds($actor));
        }

        return $query->orderBy('id')->limit(20)->get();
    }

    /** @return Collection<int, Order> */
    public function searchOrders(User $actor, string $search): Collection
    {
        $search = trim($search);
        if (mb_strlen($search) < 2) {
            return collect();
        }

        return $this->orders->orders($actor)
            ->where(fn (Builder $query): Builder => $query->where('reference', 'like', "%{$search}%")->orWhere('external_order_number', 'like', "%{$search}%"))
            ->orderByDesc('id')->limit(20)->get();
    }

    /** @return array{holders: Collection<int, array{account_id:int,type:string,label:string,available_quantity:int}>, transferable_available:int, system_unassigned:int, system_account_id:?int, requestable_available:int} */
    public function sourceAvailability(int $inventoryId, bool $lock = false): array
    {
        $query = InventoryAllocationBalance::query()
            ->with(['account.employee', 'account.team'])
            ->where('product_inventory_id', $inventoryId)
            ->whereColumn('allocated_quantity', '>', 'reserved_quantity')
            ->orderBy('account_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $balances = $query->get();
        $systemBalances = $balances->filter(fn (InventoryAllocationBalance $balance): bool => $balance->account->is_system);
        $system = (int) $systemBalances->sum(fn (InventoryAllocationBalance $balance): int => $balance->availableQuantity());
        $holders = $balances->filter(fn (InventoryAllocationBalance $balance): bool => ! $balance->account->is_system && $balance->account->status)
            ->map(fn (InventoryAllocationBalance $balance): array => [
                'account_id' => $balance->account_id,
                'type' => $balance->account->type,
                'label' => $this->accountLabel($balance->account),
                'available_quantity' => $balance->availableQuantity(),
            ])->values();

        return [
            'holders' => $holders,
            'transferable_available' => (int) $holders->sum('available_quantity'),
            'system_unassigned' => $system,
            'system_account_id' => $systemBalances->first()?->account_id,
            'requestable_available' => (int) $holders->sum('available_quantity') + $system,
        ];
    }

    public function inventoryLabel(ProductInventory $inventory): string
    {
        $availability = $this->sourceAvailability((int) $inventory->id);

        return "{$inventory->product->sku} — {$inventory->product->name} — {$inventory->warehouse->name} · Transferable: {$availability['transferable_available']} · System/Unassigned: {$availability['system_unassigned']}";
    }

    private function accountLabel(InventoryAllocationAccount $account): string
    {
        if ($account->employee !== null) {
            return trim("{$account->employee->employee_id} — {$account->employee->name}", ' —');
        }

        return $account->team?->name ?? $account->name;
    }
}
