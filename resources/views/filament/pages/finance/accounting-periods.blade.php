<x-accounting.workspace current="periods" title="Accounting Periods" description="Review period dates and controls. Posting activation is unavailable pending Owner approval.">
    <div class="grid gap-4">
        @foreach($books as $book)
            <section class="tpz-accounting-panel">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-4"><div><h2 class="m-0 text-base font-semibold">{{ $book->name }}</h2><p class="m-0 text-sm text-gray-500">{{ $book->currency }} · posting {{ $book->posting_enabled && config('accounting.posting_activation_approved', false) === true ? 'approved' : 'disabled' }}</p></div><span class="tpz-accounting-disabled">Posting activation locked</span></div>
                <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table"><thead><tr><th>Period</th><th>Dates</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                    @forelse($book->periods as $period)
                        <tr><td class="font-medium">{{ $period->name }}</td><td class="whitespace-nowrap">{{ $period->starts_on?->format('d M Y') }} – {{ $period->ends_on?->format('d M Y') }}</td><td><x-accounting.status-badge :status="ucfirst($period->status->value)" :tone="$period->status->value === 'open' ? 'success' : 'gray'" /></td><td>@if($period->status->value === 'open')<x-filament::button size="xs" color="gray" wire:click="closePeriod({{ $period->id }})" wire:confirm="Close this accounting period?">Close period</x-filament::button>@else<span class="text-gray-400">—</span>@endif</td></tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-gray-500">No periods are configured for this book.</td></tr>
                    @endforelse
                </tbody></table></div>
            </section>
        @endforeach
    </div>
</x-accounting.workspace>
