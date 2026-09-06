<?php

namespace App\Services\DemoData\Scenarios;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\ComplaintCategory;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Enums\CustomerReturnReason;
use App\Enums\CustomerReturnStatus;
use App\Enums\SafetClaimStatus;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Models\Complaint;
use App\Models\CustomerReturn;
use App\Models\DamagedStockEvent;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\WarrantyRepair;
use App\Services\Claims\SafetClaimService;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoIdentity;
use App\Services\Returns\CustomerReturnService;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\WarrantyRepairService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

final class AfterSalesScenarioBuilder
{
    public function __construct(
        private readonly DemoIdentity $identity,
        private readonly CustomerReturnService $returns,
        private readonly SafetClaimService $claims,
        private readonly WarrantyRepairService $warranties,
        private readonly ComplaintService $complaints,
    ) {}

    public function build(DemoContext $context): void
    {
        $orders = $this->returnOrders();
        $returns = $this->returns($context, $orders);
        $this->advanceClaims($context);
        $this->serviceCases($context, $orders, $returns);

        $context->count('returns', CustomerReturn::query()->where('notes', 'like', $this->identity->marker().'%')->count());
        $context->count('claims', SafetClaim::query()->whereHas('customerReturn', fn ($query) => $query->where('notes', 'like', $this->identity->marker().'%'))->count());
        $context->count('service_cases', WarrantyRepair::query()->where('notes', 'like', $this->identity->marker().'%')->count()
            + Complaint::query()->where('description', 'like', $this->identity->marker().'%')->count());
    }

    /** @return array<int, Order> */
    private function returnOrders(): array
    {
        $query = Order::query()->with(['fulfillment.items', 'platform'])->where('status', 'fulfilled')
            ->where('notes', 'like', '%'.$this->identity->marker().'%');
        $claimOrders = (clone $query)->whereHas('platform', fn ($platform) => $platform->where('customer_return_claims_enabled', true))->limit(4)->get();
        $others = (clone $query)->whereNotIn('id', $claimOrders->pluck('id'))->limit(6)->get();
        $orders = $claimOrders->concat($others)->values();
        if ($orders->count() !== 10) {
            throw new RuntimeException('The demo return scenarios require ten fulfilled demo Orders.');
        }

        return $orders->all();
    }

    /** @param array<int, Order> $orders @return array<int, CustomerReturn> */
    private function returns(DemoContext $context, array $orders): array
    {
        $created = [];
        foreach ($orders as $index => $order) {
            $number = $index + 1;
            $key = $this->identity->uuid('return/'.$number);
            $return = CustomerReturn::query()->where('idempotency_key', $key)->with('items')->first();
            $date = CarbonImmutable::today()->subDays(30 - ($number * 2))->setTime(10, 30);
            if ($return === null) {
                $fulfillmentItem = $order->fulfillment->items->first();
                $return = $this->at($date, fn (): CustomerReturn => $this->returns->create(new CreateCustomerReturnData(
                    orderId: $order->id,
                    receivingWarehouseId: $context->warehouse->id,
                    items: [[
                        'order_fulfillment_item_id' => $fulfillmentItem->id,
                        'quantity' => 1,
                        'return_reason' => $number <= 4 ? CustomerReturnReason::DamagedByCustomer->value : CustomerReturnReason::Defective->value,
                        'reason_notes' => $this->identity->note('Return reason fixture.'),
                    ]],
                    idempotencyKey: $key,
                    notes: $this->identity->note('Return fixture '.$number.'.'),
                ), $context->owner));
            }

            if ($number === 10 && $return->status === CustomerReturnStatus::Draft) {
                $return = $this->at($date->addHour(), fn (): CustomerReturn => $this->returns->cancel($return, 'Demo return cancelled before receipt.', $context->owner));
            } elseif ($number !== 8 && $return->status === CustomerReturnStatus::Draft) {
                $return = $this->at($date->addDay(), fn (): CustomerReturn => $this->returns->receive($return, $context->owner));
            }

            if ($number <= 7 && $return->status === CustomerReturnStatus::QcPending) {
                $damaged = $number <= 4 ? 1 : 0;
                $return = $this->at($date->addDays(2), fn (): CustomerReturn => $this->returns->inspect(
                    $return->items()->firstOrFail(),
                    new InspectCustomerReturnItemData(
                        sellableQuantity: $damaged ? 0 : 1,
                        damagedQuantity: $damaged,
                        postingKey: $this->identity->uuid('return-inspection/'.$number),
                        notes: $this->identity->note('QC result fixture.'),
                    ),
                    $context->owner,
                ));
            }
            $created[] = $return->refresh();
        }

        return $created;
    }

    private function advanceClaims(DemoContext $context): void
    {
        $claims = SafetClaim::query()->whereHas('customerReturn', fn ($query) => $query->where('notes', 'like', $this->identity->marker().'%'))->orderBy('id')->get();
        if ($claims->count() !== 4) {
            throw new RuntimeException('Exactly four deterministic demo Claims were expected.');
        }
        foreach ($claims as $index => $claim) {
            $number = $index + 1;
            if ($number <= 3 && $claim->status === SafetClaimStatus::NeedsFiling) {
                $claim = $this->claims->recordClaimedAmount($claim, number_format(750 + ($number * 25), 2, '.', ''), $context->owner);
                $claim = $this->claims->transition($claim, SafetClaimStatus::Filed, $context->owner, $this->identity->external('CLM', $number), notes: $this->identity->note('Claim filed fixture.'));
            }
            if ($number <= 3 && $claim->status === SafetClaimStatus::Filed) {
                $claim = $this->claims->transition($claim, SafetClaimStatus::InReview, $context->owner, notes: $this->identity->note('Claim review fixture.'));
            }
            if ($number <= 2 && $claim->status === SafetClaimStatus::InReview) {
                $claim = $this->claims->recordApproval($claim, number_format(700 + ($number * 20), 2, '.', ''), $context->owner, $this->identity->note('Claim approval fixture.'));
            }
            if ($number === 1 && $claim->status === SafetClaimStatus::Approved) {
                $claim = $this->claims->recordPayment($claim, '700.00', CarbonImmutable::today()->toDateString(), $context->owner, $this->identity->note('Claim payment fixture.'));
            }
            if ($number === 1 && $claim->status === SafetClaimStatus::Paid) {
                $this->claims->transition($claim, SafetClaimStatus::Closed, $context->owner, notes: $this->identity->note('Claim closed fixture.'));
            }
        }
    }

    /** @param array<int, Order> $orders @param array<int, CustomerReturn> $returns */
    private function serviceCases(DemoContext $context, array $orders, array $returns): void
    {
        foreach (range(1, 4) as $number) {
            $key = $this->identity->uuid('warranty/'.$number);
            $case = WarrantyRepair::query()->where('idempotency_key', $key)->first();
            $order = $orders[$number + 3];
            $date = CarbonImmutable::today()->subDays(24 - ($number * 3))->setTime(9, 0);
            if ($case === null) {
                if ($number === 4) {
                    $damage = DamagedStockEvent::query()->whereIn('product_id', $context->products->pluck('id')->concat($context->components->pluck('product_id')))->oldest('id')->firstOrFail();
                    $case = $this->at($date, fn (): WarrantyRepair => $this->warranties->createFromDamagedItem($damage, [
                        'quantity' => 1,
                        'issue_description' => 'Demo internal repair from damaged inventory.',
                        'service_provider' => 'Demo Internal Workshop',
                        'expected_return_at' => $date->addDays(14)->toDateTimeString(),
                        'notes' => $this->identity->note('Warranty fixture '.$number.'.'),
                        'idempotency_key' => $key,
                    ], $context->owner));
                } else {
                    $case = $this->at($date, fn (): WarrantyRepair => $this->warranties->create([
                        'product_id' => $order->items()->firstOrFail()->product_id,
                        'warehouse_id' => $context->warehouse->id,
                        'quantity' => 1,
                        'source' => WarrantyRepairSource::Order,
                        'issue_description' => 'Demo service case requiring controlled diagnosis.',
                        'received_at' => $date->toDateTimeString(),
                        'expected_return_at' => $date->addDays(14)->toDateTimeString(),
                        'idempotency_key' => $key,
                        'order_id' => $order->id,
                        'marketplace_platform_id' => $order->marketplace_platform_id,
                        'notes' => $this->identity->note('Warranty fixture '.$number.'.'),
                    ], $context->owner));
                }
            }
            $targets = match ($number) {
                1 => [WarrantyRepairStatus::UnderInspection],
                2 => [WarrantyRepairStatus::UnderInspection, WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::WaitingForParts],
                3 => [WarrantyRepairStatus::UnderInspection, WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted, WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending, WarrantyRepairStatus::ReadyToReturn, WarrantyRepairStatus::DispatchedBack, WarrantyRepairStatus::Completed],
                default => [],
            };
            $currentIndex = array_search($case->status, $targets, true);
            $pendingTargets = $currentIndex === false ? $targets : array_slice($targets, $currentIndex + 1);
            foreach ($pendingTargets as $offset => $target) {
                $case = $this->at($date->addHours($offset + 1), fn (): WarrantyRepair => $this->warranties->transition($case, $target, $context->owner, $this->identity->note('Warranty lifecycle fixture.')));
            }
        }

        foreach (range(1, 4) as $number) {
            $key = $this->identity->uuid('complaint/'.$number);
            $complaint = Complaint::query()->where('idempotency_key', $key)->first();
            $order = $orders[$number + 5];
            if ($complaint === null) {
                $complaint = $this->complaints->create([
                    'category' => $number % 2 === 0 ? ComplaintCategory::ProductNotWorking : ComplaintCategory::MissingAccessory,
                    'description' => $this->identity->note('Complaint fixture '.$number.'.'),
                    'quantity' => 1, 'idempotency_key' => $key,
                    'marketplace_platform_id' => $order->marketplace_platform_id,
                    'order_id' => $order->id,
                    'product_id' => $order->items()->firstOrFail()->product_id,
                ], $context->owner);
            }
            $target = match ($number) {
                1 => ComplaintStatus::InProgress,
                2 => ComplaintStatus::WaitingForCustomer,
                3 => ComplaintStatus::Resolved,
                default => ComplaintStatus::Open,
            };
            if ($complaint->status !== $target && $target !== ComplaintStatus::Open) {
                $complaint = $this->complaints->transition(
                    $complaint,
                    $target,
                    $context->owner,
                    $target === ComplaintStatus::Resolved ? ComplaintResolution::CustomerGuided : null,
                    $this->identity->note('Complaint lifecycle fixture.'),
                );
            }
        }
    }

    private function at(CarbonImmutable $when, Closure $callback): mixed
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($when);
        Carbon::setTestNow($when);
        try {
            return $callback();
        } finally {
            CarbonImmutable::setTestNow($previous);
            Carbon::setTestNow($previous);
        }
    }
}
