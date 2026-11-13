<?php

namespace App\Services\Inventory;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\InventoryReservation;
use App\Models\Order;
use App\Models\StockRequest;
use App\Models\StockRequestExecution;
use App\Models\StockRequestExecutionLine;
use App\Models\StockRequestItem;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Notifications\StockRequestNotificationDispatcher;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Orders\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StockRequestExecutionService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly InventoryAllocationService $allocations,
        private readonly OrderResponsibilityScopeService $orderScope,
        private readonly OrderService $orders,
        private readonly ActivityLogger $activity,
        private readonly StockRequestNotificationDispatcher $notifications,
        private readonly StockRequestService $requests,
        private readonly StockRequestApprovalService $approvals,
    ) {}

    public function canExecute(User $actor, StockRequest $request): bool
    {
        return $request->status === StockRequestStatus::Approved
            && $this->authorization->allows($actor, InventoryPermission::ExecuteStockRequests);
    }

    public function execute(StockRequest $request, string $idempotencyKey, User $actor): StockRequestExecution
    {
        $this->authorization->authorize($actor, InventoryPermission::ExecuteStockRequests);
        if (! $this->requests->canView($actor, $request)) {
            throw new AuthorizationException('You are not authorized to execute this Stock Request.');
        }
        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => ['required', 'uuid']])->validate();

        if ($existing = StockRequestExecution::query()->where('idempotency_key', $idempotencyKey)->first()) {
            if ($existing->stock_request_id !== $request->id) {
                throw ValidationException::withMessages(['execution' => 'This execution key belongs to another Stock Request.']);
            }

            return $existing->load('lines');
        }
        if ($existing = $request->execution()->first()) {
            return $existing->load('lines');
        }

        $execution = $request->purpose === StockRequestPurpose::ForOrder
            ? $this->executeForOrder($request, $idempotencyKey, $actor)
            : $this->executePermanentTransfer($request, $idempotencyKey, $actor);
        $this->notifications->completed($request->refresh());

        return $execution;
    }

    public function canApproveAndTransfer(User $actor, StockRequest $request): bool
    {
        return $actor->employee?->role === EmployeeRole::Owner
            && $this->authorization->allows($actor, InventoryPermission::ExecuteStockRequests)
            && $request->purpose === StockRequestPurpose::PermanentTransfer
            && ! in_array($request->status, [StockRequestStatus::Completed, StockRequestStatus::Rejected], true);
    }

    /** @param array<int, int>|null $approvedQuantities */
    public function approveAndTransfer(StockRequest $request, string $idempotencyKey, User $actor, ?array $approvedQuantities = null): StockRequestExecution
    {
        if ($actor->employee?->role !== EmployeeRole::Owner
            || ! $this->authorization->allows($actor, InventoryPermission::ExecuteStockRequests)
            || $request->purpose !== StockRequestPurpose::PermanentTransfer) {
            throw new AuthorizationException('Only an authorized Owner can approve and transfer this Stock Request.');
        }
        Validator::make(['idempotency_key' => $idempotencyKey], ['idempotency_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($request, $idempotencyKey, $actor, $approvedQuantities): StockRequestExecution {
            $locked = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($existing = $locked->execution()->with('lines')->first()) {
                if ($existing->lines()->where('idempotency_key', $idempotencyKey)->exists()) {
                    return $existing;
                }
            }
            if ($locked->status === StockRequestStatus::Rejected || $locked->status === StockRequestStatus::Completed) {
                throw ValidationException::withMessages(['request' => 'This Stock Request is no longer available for transfer.']);
            }

            foreach ($locked->sourceLines()->with('item')->where('status', StockRequestSourceStatus::Pending->value)->lockForUpdate()->get() as $line) {
                if ((int) $line->approved_quantity > 0) {
                    if (! is_array($approvedQuantities) || ! array_key_exists($line->id, $approvedQuantities)) {
                        continue;
                    }
                }
                $remaining = max(0, (int) $line->proposed_quantity - (int) $line->approved_quantity);
                $quantity = is_array($approvedQuantities)
                    ? (int) ($approvedQuantities[$line->id] ?? 0)
                    : min($remaining, $this->approvals->availableQuantity($line));
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(['approved_quantities' => 'An approval quantity exceeds the remaining requested quantity.']);
                }
                if ($quantity > 0) {
                    $this->approvals->decide($line, StockRequestSourceStatus::Approved, 'Owner approved and transferred in one operation.', (string) str()->uuid(), $actor, $quantity);
                }
            }

            $locked->refresh();
            if (! in_array($locked->status, [StockRequestStatus::Approved, StockRequestStatus::PartiallyApproved], true)) {
                throw ValidationException::withMessages(['request' => 'At least one approved stock source is required before transfer.']);
            }

            return $this->executePermanentTransfer($locked, $idempotencyKey, $actor);
        }, 5);
    }

    /** @return array<int, string> */
    public function summary(StockRequest $request): array
    {
        return $request->sourceLines()->with(['item', 'account'])->orderBy('id')->get()
            ->map(fn (StockRequestSourceLine $line): string => $request->purpose === StockRequestPurpose::ForOrder
                ? "Reserve {$line->proposed_quantity} × {$line->item->sku} from {$line->source_label} for Order {$request->order?->reference}"
                : "Transfer {$line->approved_quantity}/{$line->proposed_quantity} approved, {$line->transferred_quantity} transferred, ".max(0, (int) $line->approved_quantity - (int) $line->transferred_quantity)." remaining × {$line->item->sku} from {$line->source_label} to {$request->requester?->name}")
            ->all();
    }

    private function executeForOrder(StockRequest $request, string $idempotencyKey, User $actor): StockRequestExecution
    {
        $request->load(['requester.user', 'order.items', 'items.sourceLines.account']);
        $order = $request->order;
        $requester = $request->requester?->user;
        if (! $order instanceof Order || ! $requester instanceof User || ! $request->requester->status) {
            throw ValidationException::withMessages(['execution' => 'The linked Order or active requester is no longer available.']);
        }
        if (! $this->orderScope->canAccessOrder($requester, $order)) {
            throw ValidationException::withMessages(['execution' => 'The requester no longer has Responsibility access to every Order product.']);
        }

        [$sourcesByItemId, $sourceDistribution] = $this->orderSourceMaps($request, $order);
        $execution = null;
        try {
            $this->orders->reserveDraft(
                $order,
                $actor,
                $sourcesByItemId,
                $requester,
                function (Order $lockedOrder, Collection $items) use ($request, $actor, $idempotencyKey, $sourceDistribution, &$execution): void {
                    $lockedRequest = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
                    $this->assertApproved($lockedRequest);
                    if ($lockedRequest->execution()->exists()) {
                        throw ValidationException::withMessages(['execution' => 'This Stock Request has already been executed.']);
                    }
                    $execution = StockRequestExecution::query()->create([
                        'stock_request_id' => $lockedRequest->id,
                        'executed_by_user_id' => $actor->id,
                        'order_id' => $lockedOrder->id,
                        'execution_type' => 'order_reservation',
                        'idempotency_key' => $idempotencyKey,
                        'executed_at' => now(),
                    ]);
                    foreach ($items as $item) {
                        $reservation = $item->reservation()->firstOrFail();
                        foreach ($sourceDistribution[$item->id] ?? [] as $part) {
                            $this->createExecutionLine($execution, $part['item'], $part['source'], $part['quantity'], $reservation, null, 'order_reservation');
                        }
                    }
                    $lockedRequest->forceFill(['status' => StockRequestStatus::Completed])->save();
                    $this->activity->log('stock_request.completed', $actor, $lockedRequest, ['execution_id' => $execution->id, 'execution_type' => 'order_reservation', 'order_id' => $lockedOrder->id]);
                },
            );
        } catch (InvalidOrderTransitionException $exception) {
            if ($existing = StockRequestExecution::query()->where('stock_request_id', $request->id)->first()) {
                return $existing->load('lines');
            }

            throw $exception;
        }

        return $execution->load('lines');
    }

    private function executePermanentTransfer(StockRequest $request, string $idempotencyKey, User $actor): StockRequestExecution
    {
        return DB::transaction(function () use ($request, $idempotencyKey, $actor): StockRequestExecution {
            $locked = StockRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StockRequestStatus::Approved && $locked->status !== StockRequestStatus::PartiallyApproved) {
                throw ValidationException::withMessages(['execution' => 'This Stock Request has no approved stock available for transfer.']);
            }
            $execution = $locked->execution()->first();
            $locked->load(['requester.user', 'items.inventory', 'items.sourceLines.account']);
            if (! $locked->requester?->status || ! $locked->requester->user instanceof User) {
                throw ValidationException::withMessages(['execution' => 'The requester is no longer an active employee with a login account.']);
            }
            $destination = $this->allocations->employeeAccount($locked->requested_by_employee_id);
            $execution ??= StockRequestExecution::query()->create([
                'stock_request_id' => $locked->id, 'executed_by_user_id' => $actor->id,
                'order_id' => null, 'execution_type' => 'permanent_transfer',
                'idempotency_key' => $idempotencyKey, 'executed_at' => now(),
            ]);
            $operationKeyUsed = false;
            foreach ($locked->items as $item) {
                $transferable = $item->sourceLines->sum(fn (StockRequestSourceLine $source): int => max(0, (int) $source->approved_quantity - (int) $source->transferred_quantity));
                if ($transferable < 1) {
                    continue;
                }
                foreach ($item->sourceLines as $source) {
                    $quantity = max(0, (int) $source->approved_quantity - (int) $source->transferred_quantity);
                    if ($quantity < 1) {
                        continue;
                    }
                    $this->allocations->transferExact($item->inventory, $source->account, $destination, $quantity, $actor, $locked);
                    $source->forceFill(['transferred_quantity' => (int) $source->transferred_quantity + $quantity])->save();
                    $this->createExecutionLine($execution, $item, $source, $quantity, null, $destination->id, 'permanent_transfer', $operationKeyUsed ? null : $idempotencyKey);
                    $operationKeyUsed = true;
                }
            }
            $allTransferred = $locked->items()->with('sourceLines')->get()->every(fn (StockRequestItem $item): bool => (int) $item->sourceLines->sum('transferred_quantity') >= $item->quantity);
            $locked->forceFill(['status' => $allTransferred ? StockRequestStatus::Completed : StockRequestStatus::PartiallyApproved])->save();
            $this->activity->log('stock_request.completed', $actor, $locked, ['execution_id' => $execution->id, 'execution_type' => 'permanent_transfer']);

            return $execution->load('lines');
        }, 5);
    }

    /** @return array{0: array<int, array<int, int>>, 1: array<int, array<int, array{item: StockRequestItem, source: StockRequestSourceLine, quantity: int}>>} */
    private function orderSourceMaps(StockRequest $request, Order $order): array
    {
        $requestItems = $request->items->keyBy('product_inventory_id');
        $orderItems = $order->items()->orderBy('line_number')->orderBy('id')->get();
        $inventories = $request->items->keyBy(fn (StockRequestItem $item): string => "{$item->inventory->product_id}:{$item->inventory->warehouse_id}");
        $required = $orderItems->groupBy('product_id')->map->sum('ordered_quantity');

        foreach ($required as $productId => $quantity) {
            $requestItem = $inventories->get("{$productId}:{$order->warehouse_id}");
            if (! $requestItem instanceof StockRequestItem || $requestItem->quantity !== (int) $quantity) {
                throw ValidationException::withMessages(['execution' => 'The approved request quantities no longer match the linked Order.']);
            }
        }
        if ($required->count() !== $requestItems->count()) {
            throw ValidationException::withMessages(['execution' => 'The approved request products no longer match the linked Order.']);
        }

        $maps = [];
        $distribution = [];
        foreach ($required as $productId => $quantity) {
            $requestItem = $inventories->get("{$productId}:{$order->warehouse_id}");
            $this->assertItemFullyApproved($requestItem);
            $pool = $requestItem->sourceLines->map(fn (StockRequestSourceLine $line): array => ['line' => $line, 'remaining' => $line->proposed_quantity])->values()->all();
            foreach ($orderItems->where('product_id', (int) $productId) as $orderItem) {
                $needed = $orderItem->ordered_quantity;
                foreach ($pool as $index => $entry) {
                    if ($needed === 0 || $entry['remaining'] === 0) {
                        continue;
                    }
                    $take = min($needed, $entry['remaining']);
                    $maps[$orderItem->id][$entry['line']->inventory_allocation_account_id] = ($maps[$orderItem->id][$entry['line']->inventory_allocation_account_id] ?? 0) + $take;
                    $distribution[$orderItem->id][] = ['item' => $requestItem, 'source' => $entry['line'], 'quantity' => $take];
                    $pool[$index]['remaining'] -= $take;
                    $needed -= $take;
                }
                if ($needed > 0) {
                    throw ValidationException::withMessages(['execution' => 'Approved stock sources no longer cover the linked Order quantity.']);
                }
            }
        }

        return [$maps, $distribution];
    }

    private function assertApproved(StockRequest $request): void
    {
        if ($request->status === StockRequestStatus::Completed) {
            throw ValidationException::withMessages(['execution' => 'This Stock Request has already been executed.']);
        }
        if ($request->status === StockRequestStatus::Rejected) {
            throw ValidationException::withMessages(['execution' => 'This Stock Request was rejected and cannot be executed.']);
        }
        if ($request->status !== StockRequestStatus::Approved) {
            throw ValidationException::withMessages(['execution' => 'This Stock Request still has pending approvals.']);
        }
    }

    private function assertItemFullyApproved(StockRequestItem $item): void
    {
        if ($item->sourceLines->isEmpty()
            || $item->sourceLines->contains(fn (StockRequestSourceLine $line): bool => $line->status !== StockRequestSourceStatus::Approved)
            || (int) $item->sourceLines->sum('proposed_quantity') !== $item->quantity) {
            throw ValidationException::withMessages(['execution' => "{$item->sku} does not have complete approved source quantities."]);
        }
    }

    private function createExecutionLine(StockRequestExecution $execution, StockRequestItem $item, StockRequestSourceLine $source, int $quantity, ?InventoryReservation $reservation, ?int $destinationAccountId, string $type, ?string $idempotencyKey = null): void
    {
        StockRequestExecutionLine::query()->create([
            'stock_request_execution_id' => $execution->id,
            'stock_request_item_id' => $item->id,
            'stock_request_source_line_id' => $source->id,
            'product_inventory_id' => $item->product_inventory_id,
            'source_account_id' => $source->inventory_allocation_account_id,
            'destination_account_id' => $destinationAccountId,
            'inventory_reservation_id' => $reservation?->id,
            'quantity' => $quantity,
            'execution_type' => $type,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
