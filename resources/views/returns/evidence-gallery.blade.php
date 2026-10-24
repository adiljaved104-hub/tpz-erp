@php
    $source = $getRecord();
    $return = $source instanceof \App\Models\CustomerReturn
        ? $source
        : ($source instanceof \App\Models\SafetClaim
            ? $source->customerReturn
            : ($source instanceof \App\Models\WarrantyRepair ? $source->damagedStockEvent?->customerReturn : null));
    $allowed = $return && auth()->user() && app(\App\Services\Returns\CustomerReturnEvidenceAuthorization::class)->canView(auth()->user(), $return);
    $items = $allowed ? $return->items()->when($source instanceof \App\Models\SafetClaim,
        fn ($query) => $query->whereKey($source->customer_return_item_id))->with('findings.evidence')->get() : collect();
@endphp

@if ($allowed)
    <div class="space-y-4">
        @if ($items->sum(fn ($item) => $item->findings->count()) === 0)
            <p class="text-sm text-gray-500">No return evidence has been saved.</p>
        @endif
        @foreach ($items as $item)
            @foreach ($item->findings as $finding)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p class="font-semibold text-gray-950 dark:text-white">{{ $item->sku_snapshot }} — {{ $item->product_name_snapshot }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Finding: {{ str($finding->problem_type)->replace('_', ' ')->headline() }} · {{ $finding->recorded_at?->format('d M Y, h:i A') }}</p>
                    @if ($finding->actual_product_name || $finding->actual_serial_number)
                        <p class="text-sm text-gray-600 dark:text-gray-300">Actually received: {{ $finding->actual_product_name ?: 'Unidentified' }}@if ($finding->actual_serial_number) · Serial {{ $finding->actual_serial_number }}@endif</p>
                    @endif
                    @if ($finding->remarks)<p class="mt-2 whitespace-pre-wrap text-sm">{{ $finding->remarks }}</p>@endif
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($finding->evidence as $proof)
                            <div class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">
                                @if ($proof->media_type === 'photo')
                                    <a href="{{ route('customer-return-evidence.preview', $proof) }}" target="_blank" rel="noopener noreferrer"><img src="{{ route('customer-return-evidence.preview', $proof) }}" alt="Return evidence photo" class="max-h-48 w-full object-contain"></a>
                                @else
                                    <video controls preload="metadata" class="max-h-48 w-full"><source src="{{ route('customer-return-evidence.preview', $proof) }}" type="{{ $proof->mime_type }}"></video>
                                @endif
                                <a class="mt-2 block text-sm text-primary-600 underline" href="{{ route('customer-return-evidence.original', $proof) }}">Download original</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>
@endif
