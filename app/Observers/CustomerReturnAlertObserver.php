<?php

namespace App\Observers;

use App\Enums\CustomerReturnStatus;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturn;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\CriticalAlertRecipientResolver;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CustomerReturnAlertObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly CriticalAlertRecipientResolver $recipients, private readonly CriticalAlertDispatcher $alerts) {}

    public function saved(CustomerReturn $return): void
    {
        if ($return->status !== CustomerReturnStatus::QcPending || (! $return->wasRecentlyCreated && ! $return->wasChanged('status'))) {
            return;
        }
        $return->loadMissing('order');
        foreach ($this->recipients->customerReturn($return) as $recipient) {
            $this->alerts->send($recipient, 'return.awaiting_qc', 'return:'.$return->id.':qc-pending', [
                'category' => 'return', 'event' => 'return.awaiting_qc', 'title' => 'Return Awaiting QC',
                'message' => $return->reference.' is ready for inspection.', 'reference' => $return->reference,
                'status' => $return->status->getLabel(), 'target_type' => 'customer_return', 'target_id' => $return->id,
            ], 'Return Awaiting QC — '.$return->reference, CustomerReturnResource::getUrl('view', ['record' => $return]));
        }
    }
}
