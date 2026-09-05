<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\CancelPurchaseData;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Exceptions\InvalidPurchaseTransitionException;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CancelPurchase
{
    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(Purchase $purchase, CancelPurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::Cancel, $purchase);
        $reason = Validator::make(['reason' => trim($data->reason)], ['reason' => ['required', 'string', 'max:2000']])->validate()['reason'];

        return DB::transaction(function () use ($purchase, $reason, $actor): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::Cancel, $purchase);

            if (! in_array($purchase->status, [PurchaseStatus::Draft, PurchaseStatus::Approved], true) || $purchase->receipts()->exists()) {
                throw new InvalidPurchaseTransitionException('Only a Draft or unreceived Approved Purchase can be cancelled.');
            }

            $from = $purchase->status;
            $purchase->forceFill(['status' => PurchaseStatus::Cancelled, 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason])->save();
            $this->activity->log('purchase.cancelled', $actor, $purchase, [
                'purchase_reference' => $purchase->reference, 'from_status' => $from->value,
                'to_status' => PurchaseStatus::Cancelled->value, 'reason_recorded' => true,
            ]);

            return $purchase;
        }, 5);
    }
}
