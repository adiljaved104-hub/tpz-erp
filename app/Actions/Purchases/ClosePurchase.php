<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\ClosePurchaseData;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Events\PurchaseClosed;
use App\Exceptions\InvalidPurchaseTransitionException;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClosePurchase
{
    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(Purchase $purchase, ClosePurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::Close, $purchase);
        $reason = Validator::make(['reason' => trim($data->reason), 'confirmed' => $data->confirmed], [
            'reason' => ['required', 'string', 'max:2000'], 'confirmed' => ['accepted'],
        ])->validate()['reason'];

        $result = DB::transaction(function () use ($purchase, $reason, $actor): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::Close, $purchase);

            if (! in_array($purchase->status, [PurchaseStatus::Approved, PurchaseStatus::PartiallyReceived, PurchaseStatus::FullyReceived], true) || ! $purchase->receipts()->exists()) {
                throw new InvalidPurchaseTransitionException('Closing requires an approved or received Purchase with at least one GRN.');
            }

            $from = $purchase->status;
            $purchase->forceFill(['status' => PurchaseStatus::Closed, 'closed_by_user_id' => $actor->id, 'closed_at' => now(), 'closure_reason' => $reason])->save();
            $this->activity->log('purchase.closed', $actor, $purchase, [
                'purchase_reference' => $purchase->reference, 'from_status' => $from->value,
                'to_status' => PurchaseStatus::Closed->value, 'reason_recorded' => true,
            ]);

            return $purchase;
        }, 5);

        PurchaseClosed::dispatch($result);

        return $result;
    }
}
