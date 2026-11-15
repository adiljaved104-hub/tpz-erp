<x-accounting.workspace current="ledger" title="General Ledger" description="Posted journal lines for the selected date range. Drafts are not included." :books="$books" :book="$book">
    <section class="tpz-accounting-panel">
        <div class="tpz-accounting-toolbar">
            <label class="grid gap-1 text-xs">From <input type="date" wire:model.live="from" aria-label="From date"></label>
            <label class="grid gap-1 text-xs">To <input type="date" wire:model.live="to" aria-label="To date"></label>
            <span class="ml-auto text-xs text-gray-500">{{ $book?->currency ?? 'AED' }} · posted activity only</span>
        </div>
        <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table">
            <thead><tr><th>Date</th><th>Journal</th><th>Account</th><th>Description</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr></thead>
            <tbody>@forelse($lines as $line)<tr><td class="whitespace-nowrap">{{ $line->journal->posting_date?->format('d M Y') }}</td><td class="font-mono">{{ $line->journal->reference }}</td><td>{{ $line->account->number }} — {{ $line->account->name }}</td><td>{{ $line->description ?: '—' }}</td><td class="text-right tabular-nums">{{ number_format((float) $line->debit, 2) }}</td><td class="text-right tabular-nums">{{ number_format((float) $line->credit, 2) }}</td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-gray-500">No posted journal lines for this date range.</td></tr>@endforelse</tbody>
        </table></div>
    </section>
</x-accounting.workspace>
