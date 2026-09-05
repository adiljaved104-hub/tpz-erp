<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\CreatePurchaseData;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseDocumentService;
use App\Services\ReferenceSequenceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CreatePurchase
{
    public function __construct(
        private readonly PurchaseAuthorization $authorization,
        private readonly PurchaseDocumentService $documents,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function handle(CreatePurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::Create);
        $prepared = $this->documents->prepare($data);
        $reference = $this->references->nextPurchaseReference();

        try {
            return DB::transaction(function () use ($prepared, $reference, $actor): Purchase {
                $purchase = Purchase::query()->create(array_merge($prepared['header'], [
                    'reference' => $reference,
                    'status' => PurchaseStatus::Draft,
                    'created_by_user_id' => $actor->id,
                ]));
                $purchase->items()->createMany($prepared['lines']);
                $this->activity->log('purchase.created', $actor, $purchase, [
                    'purchase_reference' => $purchase->reference,
                    'supplier_id' => $purchase->supplier_id,
                    'supplier_specified' => $purchase->supplier_id !== null,
                    'warehouse_id' => $purchase->warehouse_id,
                    'line_count' => count($prepared['lines']),
                ]);

                return $purchase->load('items');
            }, 5);
        } catch (QueryException $exception) {
            throw $exception;
        }
    }
}
