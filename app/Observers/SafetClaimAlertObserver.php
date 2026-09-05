<?php

namespace App\Observers;

use App\Enums\SafetClaimStatus;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Models\SafetClaim;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\CriticalAlertRecipientResolver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SafetClaimAlertObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly CriticalAlertRecipientResolver $recipients, private readonly CriticalAlertDispatcher $alerts) {}

    public function saved(SafetClaim $claim): void
    {
        if ($claim->status !== SafetClaimStatus::NeedsFiling || (! $claim->wasRecentlyCreated && ! $claim->wasChanged('status'))) {
            return;
        }
        $claim->loadMissing('assignedTo.employee');
        foreach ($this->recipients->claim($claim) as $recipient) {
            $this->alerts->send($recipient, 'claim.needs_filing', 'claim:'.$claim->id.':needs-filing', [
                'category' => 'claim', 'event' => 'claim.needs_filing', 'title' => 'Claim Needs Filing',
                'message' => $claim->reference.' requires filing.', 'reference' => $claim->reference,
                'status' => $claim->status->getLabel(), 'target_type' => 'safet_claim', 'target_id' => $claim->id,
            ], '[ERP] Claim Needs Filing — '.$claim->reference, SafetClaimResource::getUrl('view', ['record' => $claim]));
        }
    }
}
