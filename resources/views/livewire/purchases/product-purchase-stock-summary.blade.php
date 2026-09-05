<div class="space-y-3">
    @foreach ($contexts as $context)
        <div class="rounded-xl border p-4">
            <div class="grid gap-2 md:grid-cols-3">
                <span>Available: {{ $context->availableQuantity }}</span>
                <span>Reserved: {{ $context->reservedQuantity }}</span>
                <span>Sellable: {{ $context->sellableQuantity() }}</span>
                <span>Damaged: {{ $context->damagedQuantity }}</span>
                <span>Total on hand: {{ $context->totalOnHand() }}</span>
                <span>Other open PO: {{ $context->outstandingOnOtherPurchases }}</span>
            </div>
            @if ($context->latestReceivedCost !== null)
                <div class="mt-2">Latest received cost: AED {{ $context->latestReceivedCost }}</div>
                <div>Weighted received cost: AED {{ $context->weightedReceivedCost }}</div>
            @endif
        </div>
    @endforeach
</div>
