<div class="space-y-5">
    <p class="text-sm text-gray-600 dark:text-gray-300">These are internal, non-posting requests. No credit note, additional tax invoice, journal or VAT return adjustment has been issued.</p>
    @foreach($proposals as $proposal)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <div class="flex flex-wrap justify-between gap-2">
                <strong>Request #{{ $proposal->id }} — {{ str_replace('_', ' ', ucfirst($proposal->status)) }}</strong>
                <span class="text-xs text-gray-500">{{ $proposal->created_at?->format('Y-m-d H:i') }}</span>
            </div>
            <p class="mt-2 text-sm">Requested by: {{ $proposal->requestedBy?->name ?? 'User #'.$proposal->requested_by_user_id }}</p>
            <p class="mt-1 text-sm">Reason: {{ $proposal->reason }}</p>
            <p class="mt-2 text-sm">Original gross: AED {{ number_format((float) $proposal->old_gross, 2) }} · Proposed gross: AED {{ number_format((float) $proposal->new_gross, 2) }} · Difference: AED {{ number_format((float) $proposal->gross_difference, 2) }}</p>
            @foreach($proposal->adjustment_documents_preview as $kind => $document)
                @if(count($document['lines'] ?? []) > 0)
                    <div class="mt-4">
                        <h4 class="font-semibold">{{ $kind === 'tax_credit_note' ? 'Potential Tax Credit Note' : 'Potential Additional Tax Invoice' }} — Review Only</h4>
                        <p class="text-xs text-gray-600 dark:text-gray-300">Difference excl. VAT: AED {{ $document['net'] }} · VAT: AED {{ $document['vat'] }} · Incl. VAT: AED {{ $document['gross'] }}</p>
                        <div class="mt-2 overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead><tr><th class="py-2">Item</th><th>Qty</th><th>Original Unit</th><th>Proposed Unit</th><th>Difference incl. VAT</th></tr></thead>
                                <tbody>
                                @foreach($document['lines'] as $line)
                                    <tr class="border-t border-gray-200 dark:border-white/10"><td class="py-2">{{ $line['description'] }}</td><td>{{ $line['quantity'] }}</td><td>{{ $line['original_price'] }}</td><td>{{ $line['proposed_price'] }}</td><td>{{ $line['difference_gross'] }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endforeach
</div>
