<?php

namespace App\Services\Claims;

use App\Enums\CustomerReturnReason;
use App\Enums\SafetClaimPermission;
use App\Enums\SafetClaimSource;
use App\Enums\SafetClaimStatus;
use App\Exceptions\SafetClaimException;
use App\Models\CustomerReturnItem;
use App\Models\DamagedStockEvent;
use App\Models\SafetClaim;
use App\Models\SafetClaimStatusEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\SafetClaimAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SafetClaimService
{
    public function __construct(
        private readonly SafetClaimAuthorization $authorization,
        private readonly ActivityLogger $activity,
        private readonly SafetClaimAssigneeService $assignees,
    ) {}

    public function eligible(CustomerReturnItem $item): bool
    {
        $item->loadMissing('customerReturn.platform');

        return $item->return_reason === CustomerReturnReason::DamagedByCustomer
            && $item->customerReturn->marketplace_platform_id !== null
            && $item->customerReturn->platform?->customer_return_claims_enabled === true;
    }

    public function createFromQcDamage(DamagedStockEvent $damage, CustomerReturnItem $item, User $actor, ?string $reference): ?SafetClaim
    {
        if (! $this->eligible($item)) {
            return null;
        }
        if ($existing = SafetClaim::query()->where('damaged_stock_event_id', $damage->id)->first()) {
            return $existing;
        }
        if ($reference === null) {
            throw new SafetClaimException('An eligible Claim requires a reserved reference.');
        }

        $item->loadMissing('customerReturn.platform');
        $return = $item->customerReturn;
        $claim = SafetClaim::query()->create([
            'reference' => $reference, 'marketplace_platform_id' => $return->marketplace_platform_id,
            'customer_return_id' => $return->id, 'customer_return_item_id' => $item->id,
            'damaged_stock_event_id' => $damage->id, 'order_id' => $return->order_id,
            'order_item_id' => $item->order_item_id, 'order_fulfillment_item_id' => $item->order_fulfillment_item_id,
            'product_id' => $item->product_id, 'quantity' => $damage->quantity,
            'source' => SafetClaimSource::QcDamagedCustomerReturn, 'status' => SafetClaimStatus::NeedsFiling,
            'claim_reason' => $item->return_reason->label(), 'claim_program_name' => $return->platform->claim_program_name,
            'idempotency_key' => $damage->idempotency_key, 'created_by_user_id' => $actor->id,
        ]);
        $this->event($claim, null, SafetClaimStatus::NeedsFiling, null, $actor);
        $this->activity->log('claim.created', $actor, $claim, ['claim_reference' => $claim->reference, 'product_id' => $claim->product_id, 'quantity' => $claim->quantity]);

        return $this->assignees->autoAssign($claim, $actor);
    }

    public function transition(SafetClaim $claim, SafetClaimStatus $to, User $actor, ?string $externalReference = null, ?string $reason = null, ?string $notes = null): SafetClaim
    {
        if ($to === SafetClaimStatus::Approved) {
            throw new SafetClaimException('Use Record Approval so the approved amount is captured.');
        }
        if ($to === SafetClaimStatus::Paid) {
            throw new SafetClaimException('Use Record Payment so the reimbursed amount and paid date are captured.');
        }
        $permission = match ($to) {
            SafetClaimStatus::Filed => SafetClaimPermission::File,
            SafetClaimStatus::Closed => SafetClaimPermission::Close,
            default => SafetClaimPermission::UpdateStatus,
        };
        $this->authorization->authorize($actor, $permission, $claim);
        $effectiveExternalReference = filled($externalReference) ? trim((string) $externalReference) : $claim->external_claim_reference;
        Validator::make(['externalReference' => $effectiveExternalReference, 'reason' => $reason, 'notes' => $notes], [
            'externalReference' => [$to === SafetClaimStatus::Filed ? 'required' : 'nullable', 'string', 'max:255'],
            'reason' => [in_array($to, [SafetClaimStatus::Rejected, SafetClaimStatus::NotEligible], true) ? 'required' : 'nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($claim, $to, $actor, $effectiveExternalReference, $reason, $notes): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $from = $locked->status;
            $allowed = [
                SafetClaimStatus::NeedsFiling->value => [SafetClaimStatus::Filed, SafetClaimStatus::NotEligible],
                SafetClaimStatus::Filed->value => [SafetClaimStatus::InReview],
                SafetClaimStatus::InReview->value => [SafetClaimStatus::Approved, SafetClaimStatus::Rejected],
                SafetClaimStatus::Approved->value => [SafetClaimStatus::Paid],
                SafetClaimStatus::Paid->value => [SafetClaimStatus::Closed],
                SafetClaimStatus::Rejected->value => [SafetClaimStatus::Closed],
            ][$from->value] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw new SafetClaimException("Cannot move Claim from {$from->getLabel()} to {$to->getLabel()}.");
            }

            $changes = ['status' => $to, 'notes' => filled($notes) ? trim($notes) : $locked->notes];
            if ($to === SafetClaimStatus::Filed) {
                $changes += ['external_claim_reference' => $effectiveExternalReference, 'filed_at' => now()];
            }
            if ($to === SafetClaimStatus::InReview) {
                $changes['reviewed_at'] = now();
            }
            if ($to === SafetClaimStatus::Approved) {
                $changes['approved_at'] = now();
            }
            if ($to === SafetClaimStatus::Rejected) {
                $changes['rejected_at'] = now();
            }
            if ($to === SafetClaimStatus::Paid) {
                $changes['paid_at'] = now();
            }
            if (in_array($to, [SafetClaimStatus::Closed, SafetClaimStatus::NotEligible], true)) {
                $changes['closed_at'] = now();
            }
            $locked->forceFill($changes)->save();
            $this->event($locked, $from, $to, filled($reason) ? $reason : $notes, $actor);
            $this->activity->log('claim.'.str_replace('needs_filing', 'created', $to->value), $actor, $locked, ['claim_reference' => $locked->reference, 'from_status' => $from->value, 'to_status' => $to->value, 'reason_present' => filled($reason)]);

            return $locked->refresh();
        });
    }

    public function updateExternalReference(SafetClaim $claim, ?string $externalReference, User $actor): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::File, $claim);
        $validated = Validator::make(compact('externalReference'), [
            'externalReference' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($claim, $validated, $actor): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            if ($locked->status !== SafetClaimStatus::NeedsFiling) {
                throw new SafetClaimException('The external reference can only be edited before the Claim is filed.');
            }
            $locked->forceFill(['external_claim_reference' => trim($validated['externalReference'])])->save();
            $this->activity->log('claim.external_reference_updated', $actor, $locked, [
                'claim_reference' => $locked->reference,
                'changed_fields' => ['external_claim_reference'],
            ]);

            return $locked->refresh();
        });
    }

    public function recordClaimedAmount(SafetClaim $claim, string $amount, User $actor): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $claim);
        $amount = $this->validateAmount($amount, 'claimed amount');

        return DB::transaction(function () use ($claim, $amount, $actor): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $locked);
            if ($locked->claimed_amount !== null) {
                throw new SafetClaimException('Claimed Amount has already been recorded. Use Edit Claimed Amount and provide a reason.');
            }
            $locked->forceFill(['claimed_amount' => $amount])->save();
            $this->activity->log('claim.claimed_amount_recorded', $actor, $locked, [
                'claim_reference' => $locked->reference,
                'changed_fields' => ['claimed_amount'],
            ]);

            return $locked->refresh();
        });
    }

    public function correctClaimedAmount(SafetClaim $claim, string $amount, string $reason, User $actor): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $claim);
        $amount = $this->validateAmount($amount, 'claimed amount');
        $validated = Validator::make(compact('reason'), [
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($claim, $amount, $validated, $actor): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $locked);
            if ($locked->claimed_amount === null) {
                throw new SafetClaimException('No Claimed Amount exists to edit. Use Record Claimed Amount instead.');
            }
            if (bccomp((string) $locked->claimed_amount, $amount, 2) === 0) {
                throw new SafetClaimException('The corrected Claimed Amount must be different from the existing amount.');
            }

            $locked->forceFill(['claimed_amount' => $amount])->save();
            $this->activity->log('claim.claimed_amount_corrected', $actor, $locked, [
                'claim_reference' => $locked->reference,
                'changed_fields' => ['claimed_amount'],
                'reason' => trim($validated['reason']),
                'previous_entry_present' => true,
            ]);

            return $locked->refresh();
        });
    }

    public function recordApproval(SafetClaim $claim, string $approvedAmount, User $actor, ?string $notes = null): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateStatus, $claim);
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $claim);
        $approvedAmount = $this->validateAmount($approvedAmount, 'approved amount');
        Validator::make(compact('notes'), ['notes' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($claim, $approvedAmount, $actor, $notes): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateStatus, $locked);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $locked);
            if ($locked->status !== SafetClaimStatus::InReview) {
                throw new SafetClaimException('Only a Claim in review can be approved.');
            }
            if ($locked->claimed_amount === null) {
                throw new SafetClaimException('Record Claimed Amount before approving this Claim.');
            }
            $locked->forceFill([
                'status' => SafetClaimStatus::Approved,
                'approved_amount' => $approvedAmount,
                'approved_at' => now(),
                'notes' => filled($notes) ? trim($notes) : $locked->notes,
            ])->save();
            $this->event($locked, SafetClaimStatus::InReview, SafetClaimStatus::Approved, $notes, $actor);
            $this->activity->log('claim.approved_amount_recorded', $actor, $locked, [
                'claim_reference' => $locked->reference,
                'changed_fields' => ['approved_amount', 'approved_at', 'status'],
            ]);

            return $locked->refresh();
        });
    }

    public function recordPayment(SafetClaim $claim, string $reimbursedAmount, mixed $paidDate, User $actor, ?string $notes = null): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateStatus, $claim);
        $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $claim);
        $reimbursedAmount = $this->validateAmount($reimbursedAmount, 'reimbursed amount');
        $validated = Validator::make(compact('paidDate', 'notes'), [
            'paidDate' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['paidDate.before_or_equal' => 'Paid date cannot be in the future.'])->validate();
        $paidAt = CarbonImmutable::parse($validated['paidDate'])->startOfDay();

        return DB::transaction(function () use ($claim, $reimbursedAmount, $paidAt, $actor, $notes): SafetClaim {
            $locked = SafetClaim::query()->lockForUpdate()->findOrFail($claim->id);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateStatus, $locked);
            $this->authorization->authorize($actor, SafetClaimPermission::UpdateFinancial, $locked);
            if ($locked->status !== SafetClaimStatus::Approved) {
                throw new SafetClaimException('Only an approved Claim can record payment.');
            }
            if ($locked->paid_at !== null || $locked->reimbursed_amount !== null) {
                throw new SafetClaimException('Payment has already been recorded for this Claim.');
            }
            if ($locked->approved_at !== null && $paidAt->lt($locked->approved_at->startOfDay())) {
                throw ValidationException::withMessages(['paidDate' => 'Paid date cannot be before the approval date.']);
            }
            $locked->forceFill([
                'status' => SafetClaimStatus::Paid,
                'reimbursed_amount' => $reimbursedAmount,
                'paid_at' => $paidAt,
                'notes' => filled($notes) ? trim($notes) : $locked->notes,
            ])->save();
            $this->event($locked, SafetClaimStatus::Approved, SafetClaimStatus::Paid, $notes, $actor);
            $this->activity->log('claim.payment_recorded', $actor, $locked, [
                'claim_reference' => $locked->reference,
                'return_reference' => $locked->customerReturn?->reference,
                'changed_fields' => ['reimbursed_amount', 'paid_at', 'status'],
            ]);

            return $locked->refresh();
        });
    }

    private function validateAmount(string $amount, string $label): string
    {
        $validated = Validator::make(['amount' => $amount], [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
        ], ['amount.gt' => ucfirst($label).' must be greater than zero.'])->validate();

        return bcadd((string) $validated['amount'], '0', 2);
    }

    private function event(SafetClaim $claim, ?SafetClaimStatus $from, SafetClaimStatus $to, ?string $reason, User $actor): void
    {
        SafetClaimStatusEvent::query()->create(['safet_claim_id' => $claim->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'changed_by_user_id' => $actor->id, 'changed_at' => now()]);
    }
}
