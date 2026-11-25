<div class="space-y-4">
    <p class="text-sm font-medium text-amber-700 dark:text-amber-300">Confirm the selling prices carefully. Clicking the button below will issue a Tax Invoice with these prices and start the PDF download.</p>
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                <th class="p-3">Product / Description</th>
                <th class="p-3 text-right">Qty</th>
                <th class="p-3 text-right">Unit Price Incl. VAT (AED)</th>
                <th class="p-3 text-right">Line Total (AED)</th>
            </tr></thead>
            <tbody>
                @foreach($items as $item)
                    @php
                        $qty = is_numeric($item['quantity'] ?? null) ? max(0, (int) $item['quantity']) : 0;
                        $price = is_numeric($item['unit_price_including_vat'] ?? null) ? max(0, (float) $item['unit_price_including_vat']) : 0;
                    @endphp
                    <tr class="border-t border-gray-200 dark:border-white/10">
                        <td class="p-3">{{ $item['description'] ?? '—' }}</td>
                        <td class="p-3 text-right">{{ $qty }}</td>
                        <td class="p-3 text-right">{{ number_format($price, 2) }}</td>
                        <td class="p-3 text-right">{{ number_format($qty * $price, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="ml-auto max-w-sm space-y-2 rounded-lg bg-gray-50 p-4 text-sm dark:bg-white/5">
        <div class="flex justify-between"><span>Subtotal excl. VAT</span><strong>AED {{ number_format((float) ($totals['subtotal'] ?? 0), 2) }}</strong></div>
        <div class="flex justify-between"><span>VAT ({{ $totals['vat_rate'] ?? 5 }}%)</span><strong>AED {{ number_format((float) ($totals['vat'] ?? 0), 2) }}</strong></div>
        <div class="flex justify-between border-t pt-2 text-base dark:border-white/10"><strong>Grand Total</strong><strong>AED {{ number_format((float) ($totals['grand_total'] ?? 0), 2) }}</strong></div>
    </div>
    <p class="text-xs text-gray-500">Choose Cancel to return to the form and correct any prices. The server validates the invoice again before saving.</p>
</div>
