<?php

namespace App\Services\Inventory;

use App\Enums\InventoryPermission;
use App\Enums\StockRequestSourceStatus;
use App\Enums\StockRequestStatus;
use App\Models\InventoryAllocationBalance;
use App\Models\StockRequest;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
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
    ): StockRequestSourceLine {
        $validated = Validator::make([
            'decision' => $decision->value,
            'note' => filled($note) ? trim((string) $note) : null,
            'idempotency_key' => $idempotencyKey,
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
        ], [
            'note.required' => 'Please enter a reason for rejecting this request.',
        ])->validate();

        return DB::transaction(function () use ($line, $decision, $validated, $actor): StockRequestSourceLine {
            $locked = StockRequestSourceLine::query()
                ->with(['account', 'item.inventory.product'])
                ->whereKey($line->id)
                ->lockForUpdate()
                ->firstOrFail();
            $request = StockRequest::query()->whereKey($locked->stock_request_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockRequestSourceStatus::Pending) {
                if ($locked->decision_idempotency_key === $validated['idempotency_key']) {
                    return $locked;
                }

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

            if ($decision === StockRequestSourceStatus::Approved) {
                $this->assertCurrentlyAvailable($locked);
            }

            $locked->forceFill([
                'status' => $decision,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $validated['note'],
                'decision_idempotency_key' => $validated['idempotency_key'],
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
    }

    private function assertCurrentlyAvailable(StockRequestSourceLine $line): void
    {
        $balance = InventoryAllocationBalance::query()
            ->where('account_id', $line->inventory_allocation_account_id)
            ->where('product_inventory_id', $line->item->product_inventory_id)
            ->lockForUpdate()
            ->first();
        $available = max(0, $balance?->availableQuantity() ?? 0);

        if ($available >= $line->proposed_quantity) {
            return;
        }

        $unit = $available === 1 ? 'unit is' : 'units are';
        $holderUnit = $available === 1 ? 'unit' : 'units';
        $message = $line->source_type === 'system'
            ? "Only {$available} unassigned {$unit} currently available; this approval requires {$line->proposed_quantity}."
            : "{$line->source_label} currently has only {$available} {$holderUnit} available; this approval requires {$line->proposed_quantity}.";

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
            $statuses->contains(StockRequestSourceStatus::Approved->value) => StockRequestStatus::PartiallyApproved,
            default => StockRequestStatus::Pending,
        };

        $request->forceFill(['status' => $status])->save();
    }
}
