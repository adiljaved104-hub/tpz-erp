<x-accounting.workspace current="trial" title="Trial Balance" description="Posted account balances through the selected date." :books="$books" :book="$book">
    <section class="tpz-accounting-panel">
        <div class="tpz-accounting-toolbar"><label class="grid gap-1 text-xs">As of date <input type="date" wire:model.live="asOfDate" aria-label="Trial balance as of date"></label><span class="ml-auto text-xs text-gray-500">{{ $book?->currency ?? 'AED' }} · posted activity only</span></div>
        <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table">
            <thead><tr><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th><th class="text-right">Balance</th></tr></thead>
            <tbody>@forelse($rows as $row)<tr><td>{{ $row['account']->number }} — {{ $row['account']->name }}</td><td class="text-right tabular-nums">{{ number_format((float) $row['debit'], 2) }}</td><td class="text-right tabular-nums">{{ number_format((float) $row['credit'], 2) }}</td><td class="text-right tabular-nums">{{ number_format((float) $row['balance'], 2) }}</td></tr>@empty<tr><td colspan="4" class="py-8 text-center text-gray-500">No posted journal balances exist through this date.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</x-accounting.workspace>
