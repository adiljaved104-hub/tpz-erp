<?php

namespace App\Actions\Purchases;

use App\DTOs\Purchases\ApprovePurchaseData;
use App\Enums\EmployeeRole;
use App\Enums\ProductStatus;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Events\PurchaseApproved;
use App\Exceptions\InvalidPurchaseTransitionException;
use App\Models\Employee;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ApprovePurchase
{
    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(Purchase $purchase, ApprovePurchaseData $data, User $actor): Purchase
    {
        $this->authorization->authorize($actor, PurchasePermission::Approve, $purchase);
        $validated = Validator::make(['reason' => trim($data->reason), 'confirmed' => $data->confirmed], [
            'reason' => ['required', 'string', 'max:2000'], 'confirmed' => ['accepted'],
        ])->validate();

        $result = DB::transaction(function () use ($purchase, $validated, $actor): Purchase {
            $purchase = Purchase::query()->with('items.product')->lockForUpdate()->findOrFail($purchase->id);
            $this->authorization->authorize($actor, PurchasePermission::Approve, $purchase);

            if ($purchase->status !== PurchaseStatus::Draft) {
                throw new InvalidPurchaseTransitionException('Only a Draft Purchase can be approved.');
            }

            if (($purchase->supplier_id !== null && ! $purchase->supplier()->where('status', true)->exists())
                || ! $purchase->warehouse()->where('status', true)->exists()
                || $purchase->items->isEmpty() || $purchase->items->contains(fn ($item): bool => $item->product->status !== ProductStatus::Active)) {
                throw ValidationException::withMessages(['status' => 'Approval requires an active Warehouse, active Products, at least one line, and an active Supplier when specified.']);
            }

            $selfApproved = $purchase->created_by_user_id === $actor->id;

            if ($selfApproved) {
                $activeOwners = Employee::query()->where('status', true)->where('role', EmployeeRole::Owner->value)->whereNotNull('user_id')->count();

                if ($activeOwners !== 1 || $actor->employee?->role !== EmployeeRole::Owner) {
                    throw new InvalidPurchaseTransitionException('Self-approval is allowed only for the sole active Owner.');
                }
            }

            $purchase->forceFill([
                'status' => PurchaseStatus::Approved,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
                'approval_reason' => $validated['reason'],
                'self_approved' => $selfApproved,
            ])->save();
            $this->activity->log('purchase.approved', $actor, $purchase, [
                'purchase_reference' => $purchase->reference,
                'from_status' => PurchaseStatus::Draft->value,
                'to_status' => PurchaseStatus::Approved->value,
                'self_approved' => $selfApproved,
                'reason_recorded' => true,
            ]);

            return $purchase;
        }, 5);

        PurchaseApproved::dispatch($result);

        return $result;
    }
}
