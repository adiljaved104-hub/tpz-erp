<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\CustomerReturnSource;
use App\Enums\CustomerReturnStatus;
use App\Enums\OrderStatus;
use App\Enums\ReturnRefundStatus;
use App\Enums\ReturnRefundType;
use App\Exceptions\ReturnRefundException;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnRefund;
use App\Models\CustomerReturnStatusEvent;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReturnRefundService
{
    public function __construct(
        private readonly CustomerReturnAuthorization $authorization,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    public function recordForReturn(CustomerReturn $return, string $amount, mixed $refundDate, ?string $externalReference, ?string $note, string $idempotencyKey, User $actor): CustomerReturnRefund
    {
        $this->authorization->authorize($actor, CustomerReturnPermission::RecordRefund, $return);
        $values = $this->validate($amount, $refundDate, $externalReference, $note, $idempotencyKey);

        return $this->transaction(function () use ($return, $values, $actor): CustomerReturnRefund {
            $locked = CustomerReturn::query()->lockForUpdate()->findOrFail($return->id);
            $this->authorization->authorize($actor, CustomerReturnPermission::RecordRefund, $locked);

            return $this->createRefund($locked, null, $this->typeFor($locked), $values, $actor);
        });
    }

    public function recordForWarranty(WarrantyRepair $warranty, string $amount, mixed $refundDate, ?string $externalReference, ?string $note, string $idempotencyKey, User $actor): CustomerReturnRefund
    {
        $this->authorization->authorize($actor, CustomerReturnPermission::RecordRefund);
        if ($warranty->isInternalCompanyOwnedRepair() || $warranty->order_id === null || $warranty->product_id === null) {
            throw new ReturnRefundException('Only an external Warranty case with an Order can record a customer refund.');
        }
        if (! $this->responsibilities->canAccessOrder($actor, $warranty->order)) {
            throw new AuthorizationException;
        }
        $values = $this->validate($amount, $refundDate, $externalReference, $note, $idempotencyKey);
        $reservedReturnReference = $warranty->customer_return_id === null
            ? $this->references->nextCustomerReturnReference()
            : null;

        return $this->transaction(function () use ($warranty, $values, $actor, $reservedReturnReference): CustomerReturnRefund {
            $lockedWarranty = WarrantyRepair::query()->with('order')->lockForUpdate()->findOrFail($warranty->id);
            if ($lockedWarranty->isInternalCompanyOwnedRepair() || $lockedWarranty->order_id === null) {
                throw new ReturnRefundException('Only an external Warranty case with an Order can record a customer refund.');
            }
            if (! $this->responsibilities->canAccessOrder($actor, $lockedWarranty->order)) {
                throw new AuthorizationException;
            }

            $return = $lockedWarranty->customer_return_id === null
                ? $this->createFinancialReturn($lockedWarranty, $reservedReturnReference, $values['refundDate'], $values['note'], $actor)
                : CustomerReturn::query()->lockForUpdate()->findOrFail($lockedWarranty->customer_return_id);

            $this->authorization->authorize($actor, CustomerReturnPermission::RecordRefund, $return);
            $refund = $this->createRefund($return, $lockedWarranty, ReturnRefundType::WarrantyRefund, $values, $actor);

            if ($lockedWarranty->customer_return_id === null) {
                $lockedWarranty->forceFill(['customer_return_id' => $return->id])->save();
                $this->activity->log('warranty.refund_linked', $actor, $lockedWarranty, [
                    'warranty_reference' => $lockedWarranty->reference,
                    'return_reference' => $return->reference,
                ]);
            }

            return $refund;
        });
    }

    public function suggestedAmountForReturn(CustomerReturn $return): ?string
    {
        $items = $return->items()->with('orderItem:id,ordered_quantity,line_total')->get();
        if ($items->isEmpty()) {
            return null;
        }

        $total = '0.0000';
        foreach ($items as $item) {
            if ($item->orderItem === null || $item->orderItem->ordered_quantity < 1) {
                return null;
            }
            $portion = bcdiv(
                bcmul((string) $item->orderItem->line_total, (string) $item->return_quantity, 4),
                (string) $item->orderItem->ordered_quantity,
                4,
            );
            $total = bcadd($total, $portion, 4);
        }

        return $this->roundMoney($total);
    }

    public function suggestedAmountForWarranty(WarrantyRepair $warranty): ?string
    {
        if ($warranty->order_id === null || $warranty->product_id === null) {
            return null;
        }
        $item = $warranty->order?->items()->where('product_id', $warranty->product_id)->first(['ordered_quantity', 'line_total']);
        if ($item === null || $item->ordered_quantity < 1) {
            return null;
        }

        return $this->roundMoney(bcdiv(
            bcmul((string) $item->line_total, (string) $warranty->quantity, 4),
            (string) $item->ordered_quantity,
            4,
        ));
    }

    /** @return array{amount:string,refundDate:CarbonImmutable,externalReference:?string,note:?string,idempotencyKey:string} */
    private function validate(string $amount, mixed $refundDate, ?string $externalReference, ?string $note, string $idempotencyKey): array
    {
        $validated = Validator::make([
            'amount' => $amount,
            'refundDate' => $refundDate,
            'externalReference' => $externalReference,
            'note' => $note,
            'idempotencyKey' => $idempotencyKey,
        ], [
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'refundDate' => ['required', 'date', 'before_or_equal:today'],
            'externalReference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'idempotencyKey' => ['required', 'uuid'],
        ], ['refundDate.before_or_equal' => 'Refund date cannot be in the future.'])->validate();

        return [
            'amount' => bcadd((string) $validated['amount'], '0', 2),
            'refundDate' => CarbonImmutable::parse($validated['refundDate'])->startOfDay(),
            'externalReference' => filled($validated['externalReference'] ?? null) ? trim($validated['externalReference']) : null,
            'note' => filled($validated['note'] ?? null) ? trim($validated['note']) : null,
            'idempotencyKey' => $validated['idempotencyKey'],
        ];
    }

    private function createRefund(CustomerReturn $return, ?WarrantyRepair $warranty, ReturnRefundType $type, array $values, User $actor): CustomerReturnRefund
    {
        if ($existing = CustomerReturnRefund::query()->where('idempotency_key', $values['idempotencyKey'])->first()) {
            if ($existing->customer_return_id !== $return->id || $existing->warranty_repair_id !== $warranty?->id) {
                throw new ReturnRefundException('This refund request conflicts with an existing transaction.');
            }

            return $existing;
        }
        if (CustomerReturnRefund::query()->where('customer_return_id', $return->id)->exists()) {
            throw new ReturnRefundException('A customer refund has already been recorded for this Return.');
        }
        if ($warranty !== null && CustomerReturnRefund::query()->where('warranty_repair_id', $warranty->id)->exists()) {
            throw new ReturnRefundException('A customer refund has already been recorded for this Warranty case.');
        }

        $refund = CustomerReturnRefund::query()->create([
            'customer_return_id' => $return->id,
            'warranty_repair_id' => $warranty?->id,
            'return_type' => $type,
            'status' => ReturnRefundStatus::Refunded,
            'refund_amount' => $values['amount'],
            'currency' => 'AED',
            'refund_date' => $values['refundDate'],
            'external_refund_reference' => $values['externalReference'],
            'note' => $values['note'],
            'idempotency_key' => $values['idempotencyKey'],
            'recorded_by_user_id' => $actor->id,
        ]);
        $this->activity->log('return.refund_recorded', $actor, $return, [
            'return_reference' => $return->reference,
            'warranty_reference' => $warranty?->reference,
            'changed_fields' => ['refund_amount', 'refund_date', 'external_refund_reference', 'note'],
        ]);

        return $refund;
    }

    private function createFinancialReturn(WarrantyRepair $warranty, ?string $reference, CarbonImmutable $refundDate, ?string $note, User $actor): CustomerReturn
    {
        if ($reference === null) {
            throw new ReturnRefundException('A Return reference must be reserved before recording this refund.');
        }
        $order = Order::query()->with('fulfillment')->lockForUpdate()->findOrFail($warranty->order_id);
        if ($order->status !== OrderStatus::Fulfilled || $order->fulfillment === null) {
            throw new ReturnRefundException('Only a fulfilled Order can be linked to a customer refund.');
        }
        $fulfillmentItem = OrderFulfillmentItem::query()
            ->with('orderItem:id,sku,product_name')
            ->where('order_fulfillment_id', $order->fulfillment->id)
            ->whereHas('orderItem', fn ($query) => $query->where('product_id', $warranty->product_id))
            ->first();
        if ($fulfillmentItem === null || $warranty->quantity > $fulfillmentItem->quantity) {
            throw new ReturnRefundException('The Warranty item cannot be matched safely to the fulfilled Order.');
        }

        $return = CustomerReturn::query()->create([
            'reference' => $reference,
            'order_id' => $order->id,
            'marketplace_platform_id' => $order->marketplace_platform_id,
            'fulfillment_warehouse_id' => $order->warehouse_id,
            'status' => CustomerReturnStatus::Completed,
            'return_source' => CustomerReturnSource::Manual,
            'receiving_warehouse_id' => null,
            'reported_at' => $refundDate,
            'completed_at' => $refundDate,
            'created_by_user_id' => $actor->id,
            'notes' => $note,
            'idempotency_key' => $warranty->idempotency_key,
        ]);
        CustomerReturnItem::query()->create([
            'customer_return_id' => $return->id,
            'order_item_id' => $fulfillmentItem->order_item_id,
            'order_fulfillment_item_id' => $fulfillmentItem->id,
            'product_id' => $warranty->product_id,
            'sku_snapshot' => $fulfillmentItem->orderItem->sku,
            'product_name_snapshot' => $fulfillmentItem->orderItem->product_name,
            'fulfilled_quantity_snapshot' => $fulfillmentItem->quantity,
            'return_quantity' => $warranty->quantity,
            'inventory_unit_cost' => $fulfillmentItem->inventory_unit_cost,
            'return_reason' => CustomerReturnReason::Other,
            'reason_notes' => 'Financial Warranty refund; no inventory receipt was recorded.',
        ]);
        CustomerReturnStatusEvent::query()->create([
            'customer_return_id' => $return->id,
            'from_status' => null,
            'to_status' => CustomerReturnStatus::Completed,
            'actor_user_id' => $actor->id,
            'reason' => 'Warranty customer refund recorded without inventory movement.',
            'created_at' => now(),
        ]);
        $this->activity->log('customer_return.financial_created', $actor, $return, [
            'return_reference' => $return->reference,
            'order_id' => $order->id,
            'warranty_reference' => $warranty->reference,
        ]);

        return $return;
    }

    private function transaction(callable $operation): CustomerReturnRefund
    {
        try {
            return DB::transaction($operation, 5);
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                throw new ReturnRefundException('This refund has already been recorded.');
            }

            throw $exception;
        }
    }

    private function typeFor(CustomerReturn $return): ReturnRefundType
    {
        return $return->return_source === CustomerReturnSource::Marketplace
            ? ReturnRefundType::MarketplaceRefund
            : ReturnRefundType::CustomerReturn;
    }

    private function roundMoney(string $amount): string
    {
        return bcadd($amount, '0.005', 2);
    }
}
