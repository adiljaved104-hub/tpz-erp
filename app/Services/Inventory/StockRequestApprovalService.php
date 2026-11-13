<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\StockRequest;
use App\Models\StockRequestSourceApprovalEvent;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Notifications\StockRequestNotificationDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockRequestApprovalService
{
    public function __construct(
        private readonly InventoryAuthorization $authorization,
        private readonly ActivityLogger $activity,
        private readonly StockRequestNotificationDispatcher $notifications,
    ) {}

    public function canDecide(User $actor, StockRequestSourceLine $line): bool
    {
        if ($this->authorization->allows($actor, InventoryPermission::DecideAllStockRequestSources)) {
            return true;
        }

        if ($line->source_type !== 'employee'
            || ! $this->authorization->allows($actor, InventoryPermission::DecideOwnStockRequestSources)) {
            return false;
        }

        return $line->account()->where('employee_id', $actor->employee?->id)->exists();
    }

    public function availableQuantity(StockRequestSourceLine $line): int
    {
        $balance = InventoryAllocationBalance::query()
            ->where('account_id', $line->inventory_allocation_account_id)
            ->where('product_inventory_id', $line->item?->product_inventory_id)
            ->lockForUpdate()
            ->first();

        return max(0, (int) ($balance?->availableQuantity() ?? 0));
    }

    /** @return Collection<int, StockRequestSourceLine> */
    public function eligiblePendingLines(StockRequest $request, User $actor): Collection
    {
        if ($request->status === StockRequestStatus::Rejected) {
            return collect();
        }

        return $request->sourceLines()
            ->with(['account.employee', 'account.team', 'item'])
            ->where('status', StockRequestSourceStatus::Pending->value)
            ->orderBy('id')
            ->get()
            ->filter(fn (StockRequestSourceLine $line): bool => $this->canDecide($actor, $line))
            ->values();
    }

    public function decide(
        StockRequestSourceLine $line,
        StockRequestSourceStatus $decision,
        ?string $note,
        string $idempotencyKey,
        User $actor,
        ?int $approvedQuantity = null,
    ): StockRequestSourceLine {
        $validated = Validator::make([
            'decision' => $decision->value,
            'note' => filled($note) ? trim((string) $note) : null,
            'idempotency_key' => $idempotencyKey,
            'approved_quantity' => $approvedQuantity,
        ], [
            'decision' => ['required', Rule::in([
                StockRequestSourceStatus::Approved->value,
                StockRequestSourceStatus::Rejected->value,
            ])],
            'note' => [
                Rule::requiredIf($decision === StockRequestSourceStatus::Rejected),
                'nullable', 'string', 'max:2000',
            ],
            'idempotency_key' => ['required', 'uuid'],
            'approved_quantity' => ['nullable', 'integer', 'min:1'],
        ], [
            'note.required' => 'Please enter a reason for rejecting this request.',
        ])->validate();

        $result = DB::transaction(function () use ($line, $decision, $validated, $actor): StockRequestSourceLine {
            $locked = StockRequestSourceLine::query()
                ->with(['account', 'item.inventory.product'])
                ->whereKey($line->id)
                ->lockForUpdate()
                ->firstOrFail();
            $request = StockRequest::query()->whereKey($locked->stock_request_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockRequestSourceStatus::Pending && $locked->decision_idempotency_key === $validated['idempotency_key']) {
                return $locked;
            }

            if ($locked->status !== StockRequestSourceStatus::Pending) {
                $status = strtolower($locked->status->getLabel());
                throw ValidationException::withMessages([
                    'source_line_id' => "This stock source has already been {$status}.",
                ]);
            }

            if ($request->status === StockRequestStatus::Rejected) {
                throw ValidationException::withMessages([
                    'source_line_id' => 'This Stock Request has already been rejected.',
                ]);
            }

            if (! $this->canDecide($actor, $locked)) {
                throw new AuthorizationException('You are not authorized to approve this stock source.');
            }

            $remaining = max(0, $locked->proposed_quantity - (int) $locked->approved_quantity);
            $quantity = $decision === StockRequestSourceStatus::Approved
                ? (int) ($validated['approved_quantity'] ?? $remaining)
                : 0;
            if ($decision === StockRequestSourceStatus::Approved) {
                if ($quantity < 1 || $quantity > $remaining) {
                    throw ValidationException::withMessages(['approved_quantity' => 'Enter an approval quantity within the remaining requested quantity.']);
                }
                $this->assertCurrentlyAvailable($locked, $quantity);
                if (StockRequestSourceApprovalEvent::query()->where('idempotency_key', $validated['idempotency_key'])->exists()) {
                    return $locked;
                }
                StockRequestSourceApprovalEvent::query()->create([
                    'stock_request_source_line_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'quantity' => $quantity,
                    'decision' => $decision->value,
                    'note' => $validated['note'],
                    'idempotency_key' => $validated['idempotency_key'],
                    'created_at' => now(),
                ]);
            }

            $locked->forceFill([
                'status' => $decision === StockRequestSourceStatus::Approved
                    ? ((int) $locked->approved_quantity + $quantity >= $locked->proposed_quantity
                        ? StockRequestSourceStatus::Approved : StockRequestSourceStatus::Pending)
                    : $decision,
                'approved_quantity' => (int) $locked->approved_quantity + $quantity,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $validated['note'],
                'decision_idempotency_key' => $decision === StockRequestSourceStatus::Approved && (int) $locked->approved_quantity + $quantity >= $locked->proposed_quantity
                    ? $validated['idempotency_key'] : null,
            ])->save();

            $this->refreshRequestStatus($request);
            $this->activity->log(
                $decision === StockRequestSourceStatus::Approved
                    ? 'stock_request.source_approved'
                    : 'stock_request.source_rejected',
                $actor,
                $request,
                [
                    'source_line_id' => $locked->id,
                    'source_account_id' => $locked->inventory_allocation_account_id,
                    'source_type' => $locked->source_type,
                    'decision' => $decision->value,
                    'note_recorded' => filled($validated['note']),
                ],
            );

            return $locked->refresh();
        }, 5);
        $this->notifications->decided($result);

        return $result;
    }

    private function assertCurrentlyAvailable(StockRequestSourceLine $line, int $quantity): void
    {
        $available = $this->availableQuantity($line);

        if ($available >= $quantity) {
            return;
        }

        $unit = $available === 1 ? 'unit is' : 'units are';
        $holderUnit = $available === 1 ? 'unit' : 'units';
        $message = $line->source_type === 'system'
            ? "Only {$available} unassigned {$unit} currently available; this approval requires {$quantity}."
            : "{$line->source_label} currently has only {$available} {$holderUnit} available; this approval requires {$quantity}.";

        throw ValidationException::withMessages(['source_line_id' => $message]);
    }

    private function refreshRequestStatus(StockRequest $request): void
    {
        $statuses = StockRequestSourceLine::query()
            ->where('stock_request_id', $request->id)
            ->toBase()
            ->pluck('status');

        $status = match (true) {
            $statuses->contains(StockRequestSourceStatus::Rejected->value) => StockRequestStatus::Rejected,
            $statuses->isNotEmpty() && $statuses->every(fn (string $value): bool => $value === StockRequestSourceStatus::Approved->value) => StockRequestStatus::Approved,
            StockRequestSourceLine::query()->where('stock_request_id', $request->id)->where('approved_quantity', '>', 0)->exists() => StockRequestStatus::PartiallyApproved,
            default => StockRequestStatus::Pending,
        };

        $request->forceFill(['status' => $status])->save();
    }
}
