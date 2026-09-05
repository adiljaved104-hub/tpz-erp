<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\UpdatePurchaseData;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Exceptions\InvalidPurchaseTransitionException;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseDocumentService;
use Illuminate\Support\Facades\DB;

class UpdateDraftPurchase
{
    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly PurchaseDocumentService $documents, private readonly ActivityLogger $activity) {}

    public function handle(Purchase $purchase, UpdatePurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::UpdateDraft, $purchase);
        $prepared = $this->documents->prepare($data, $purchase);

        return DB::transaction(function () use ($purchase, $prepared, $actor): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::UpdateDraft, $purchase);

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new InvalidPurchaseTransitionException('Only Draft Purchases can be edited.');
            }

            $purchase->forceFill($prepared['header'])->save();
            $purchase->items()->delete();
            $purchase->items()->createMany($prepared['lines']);
            $this->activity->log('purchase.updated', $actor, $purchase, [
                'purchase_reference' => $purchase->reference,
                'changed_fields' => ['header', 'items'],
                'line_count' => count($prepared['lines']),
            ]);

            return $purchase->load('items');
        }, 5);
    }
}
