<div class="space-y-5">
    @foreach($corrections as $correction)
        <div class="rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
            <p class="mb-2 font-semibold">Correction #{{ $correction->id }} · {{ $correction->created_at?->format('d M Y, H:i') }}</p>
            <p><strong>Original:</strong> <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\TaxInvoices\TaxInvoiceResource::getUrl('view', ['record' => $correction->originalInvoice]) }}">{{ $correction->originalInvoice?->invoice_number }}</a> (void)</p>
            <p><strong>Replacement:</strong> <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\TaxInvoices\TaxInvoiceResource::getUrl('view', ['record' => $correction->replacementInvoice]) }}">{{ $correction->replacementInvoice?->invoice_number }}</a> (issued)</p>
            <p><strong>Corrected by:</strong> {{ $correction->actor?->name ?? 'Unknown' }}</p>
            <p><strong>Reason:</strong> {{ $correction->reason }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left">
                    <thead><tr><th class="py-2">Product</th><th>Qty</th><th>Old Unit Price AED</th><th>New Unit Price AED</th></tr></thead>
                    <tbody>
                        @foreach($correction->originalInvoice?->items ?? [] as $item)
                            @php($newItem = $correction->replacementInvoice?->items?->firstWhere('line_number', $item->line_number))
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="py-2">{{ $item->description }}</td>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ number_format((float) $item->unit_price_including_vat, 2) }}</td>
                                <td>{{ $newItem ? number_format((float) $newItem->unit_price_including_vat, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>
