<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnPermission;
use App\Enums\SafetClaimStatus;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnRefund;
use App\Models\SafetClaim;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

class ReturnFinancialReadService
{
    public function __construct(
        private readonly CustomerReturnAuthorization $authorization,
        private readonly CustomerReturnReadService $returns,
    ) {}

    public function paidRecovery(CustomerReturn $return, User $user): string
    {
        $this->authorization->authorize($user, CustomerReturnPermission::ViewRefundAmount, $return);

        $claims = $return->relationLoaded('claims')
            ? $return->claims
            : $return->claims()->select(['id', 'customer_return_id', 'status', 'paid_at', 'reimbursed_amount'])->get();

        return $claims
            ->filter(fn ($claim): bool => $claim->paid_at !== null
                && $claim->reimbursed_amount !== null
                && in_array($claim->status, [SafetClaimStatus::Paid, SafetClaimStatus::Closed], true))
            ->reduce(fn (string $total, $claim): string => bcadd($total, (string) $claim->reimbursed_amount, 2), '0.00');
    }

    public function netExposure(CustomerReturn $return, User $user): ?string
    {
        $this->authorization->authorize($user, CustomerReturnPermission::ViewRefundAmount, $return);
        $refund = $return->relationLoaded('refund')
            ? $return->refund
            : $return->refund()->select(['id', 'customer_return_id', 'refund_amount'])->first();

        return $refund === null ? null : bcsub((string) $refund->refund_amount, $this->paidRecovery($return, $user), 2);
    }

    /** @return array{returned_orders:int,refunded_orders:int,refund_amount:string,paid_claim_recovery:string,net_refund_exposure:string,warranty_refunds:int} */
    public function metrics(User $user): array
    {
        if (! $this->authorization->allows($user, CustomerReturnPermission::ViewRefundAmount)) {
            throw new AuthorizationException;
        }
        $scoped = $this->returns->query($user);
        $scopedIds = (clone $scoped)->select('customer_returns.id');
        $refunds = CustomerReturnRefund::query()->whereIn('customer_return_id', clone $scopedIds);
        $refundAmount = bcadd((string) (clone $refunds)->where('status', 'refunded')->sum('refund_amount'), '0', 2);
        $paidRecovery = bcadd((string) SafetClaim::query()
            ->whereIn('customer_return_id', clone $scopedIds)
            ->whereNotNull('paid_at')->whereNotNull('reimbursed_amount')
            ->whereIn('status', [SafetClaimStatus::Paid->value, SafetClaimStatus::Closed->value])
            ->sum('reimbursed_amount'), '0', 2);

        return [
            'returned_orders' => (clone $scoped)->whereNotNull('order_id')->distinct()->count('order_id'),
            'refunded_orders' => (clone $refunds)->where('status', 'refunded')->count(),
            'refund_amount' => $refundAmount,
            'paid_claim_recovery' => $paidRecovery,
            'net_refund_exposure' => bcsub($refundAmount, $paidRecovery, 2),
            'warranty_refunds' => (clone $refunds)->where('return_type', 'warranty_refund')->count(),
        ];
    }
}
