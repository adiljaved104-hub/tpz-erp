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
use App\Services\Purchases\PurchaseHandlerResolver;
use Illuminate\Support\Facades\DB;

class UpdateDraftPurchase
{
    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly PurchaseDocumentService $documents, private readonly ActivityLogger $activity, private readonly PurchaseHandlerResolver $handlers) {}

    public function handle(Purchase $purchase, UpdatePurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::UpdateDraft, $purchase);
        $prepared = $this->documents->prepare($data, $purchase, $actor);

        return DB::transaction(function () use ($purchase, $data, $prepared, $actor): Purchase {
            $purchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::UpdateDraft, $purchase);

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new InvalidPurchaseTransitionException('Only Draft Purchases can be edited.');
            }

            $handlerId = $this->handlers->handlerForUpdate(
                collect($prepared['lines'])->pluck('product_id')->all(),
                (int) $prepared['header']['warehouse_id'],
                $data->handledByEmployeeId,
                $purchase->handled_by_employee_id,
                $actor,
            );
            $handlerChanged = $handlerId !== $purchase->handled_by_employee_id;
            $purchase->forceFill([...$prepared['header'], 'handled_by_employee_id' => $handlerId])->save();
            $purchase->items()->delete();
            $purchase->items()->createMany($prepared['lines']);
            $this->activity->log('purchase.updated', $actor, $purchase, [
                'purchase_reference' => $purchase->reference,
                'changed_fields' => $handlerChanged ? ['header', 'items', 'handled_by_employee_id'] : ['header', 'items'],
                'handled_by_employee_id' => $purchase->handled_by_employee_id,
                'line_count' => count($prepared['lines']),
            ]);

            return $purchase->load('items');
        }, 5);
    }
}
