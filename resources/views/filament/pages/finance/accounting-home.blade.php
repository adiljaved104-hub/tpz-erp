<x-filament-panels::page>
    <div class="space-y-6">
        <x-accounting.page-header title="Accounting Home" description="A focused workspace for the UAE accounting book and the B1 controls currently available.">
            <x-slot:actions>
                <label class="flex min-w-48 flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                    <span>Accounting book</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="bookId" aria-label="Accounting book">
                            @foreach ($books as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->currency }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <label class="flex min-w-44 flex-col gap-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                    <span>Accounting period</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="periodId" aria-label="Accounting period">
                            @foreach ($periods as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ ucfirst($item->status->value) }}</option>@endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
            </x-slot:actions>
        </x-accounting.page-header>

        @if (! $book)
            <x-accounting.empty-state title="No accounting book is configured" description="The B1 accounting book setup is required before Accounting Home can show a book or period." />
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-filament::section compact>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Accounting book</p>
                    <p class="mt-2 text-lg font-semibold">{{ $book->name }}</p>
                    <p class="mt-1 text-sm text-gray-500">Base currency · {{ $book->currency }}</p>
                </x-filament::section>
                <x-filament::section compact>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Selected period</p>
                    @if ($period)
                        <p class="mt-2 text-lg font-semibold">{{ $period->name }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ $period->starts_on?->format('d M Y') }} – {{ $period->ends_on?->format('d M Y') }}</p>
                    @else
                        <p class="mt-2 text-sm text-gray-500">No period is configured for this book.</p>
                    @endif
                </x-filament::section>
                @if ($canViewChart)
                    <x-filament::section compact>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Chart of Accounts</p>
                        <p class="mt-2 text-lg font-semibold">{{ number_format($accountCount) }} accounts</p>
                        <p class="mt-1 text-sm text-gray-500">Current B1 account records</p>
                    </x-filament::section>
                @endif
                <x-filament::section compact>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Journal posting</p>
                    <p class="mt-2 text-lg font-semibold">Disabled</p>
                    <p class="mt-1 text-sm text-gray-500">Draft review is available; posting activation is off.</p>
                </x-filament::section>
            </div>

            <section aria-labelledby="accounting-workflows-heading" class="space-y-3">
                <div><h2 id="accounting-workflows-heading" class="text-base font-semibold">Accounting workflows</h2><p class="text-sm text-gray-500">Available B1 tools and clearly marked integration gaps.</p></div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <x-filament::section compact>
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold">Customers &amp; Receivables</h3><p class="mt-1 text-sm text-gray-500">Customer invoices, receipts and AR are not connected to Accounting B1 yet.</p></div><x-accounting.status-badge status="Not connected" /></div>
                    </x-filament::section>
                    <x-filament::section compact>
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold">Vendors &amp; Payables</h3><p class="mt-1 text-sm text-gray-500">Supplier bills, credits and AP are not connected to Accounting B1 yet.</p></div><x-accounting.status-badge status="Not connected" /></div>
                    </x-filament::section>
                    <x-filament::section compact>
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold">Banking</h3><p class="mt-1 text-sm text-gray-500">Bank registers, statement matching and reconciliation are not connected.</p></div><x-accounting.status-badge status="Not connected" /></div>
                    </x-filament::section>
                    <x-filament::section compact>
                        <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold">Inventory Accounting</h3><p class="mt-1 text-sm text-gray-500">Inventory valuation and COGS journals are not connected to Accounting B1.</p></div><x-accounting.status-badge status="Not connected" /></div>
                    </x-filament::section>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-2">
                <x-filament::section heading="Quick actions" description="Shortcuts are shown only when your Accounting permission allows the destination." compact>
                    @if ($navigationLinks->isNotEmpty())
                        <x-accounting.navigation :links="$navigationLinks" />
                    @else
                        <x-accounting.empty-state title="No Accounting shortcuts available" description="Ask an administrator to grant the specific Accounting page permission you need." />
                    @endif
                </x-filament::section>

                <x-filament::section heading="Recent accounting activity" description="Recent journal records in the selected book. Drafts are not posted to the ledger." compact>
                    @if ($canViewJournals)
                        <x-accounting.data-table minimum-width="40rem" class="border-0 shadow-none dark:border-0">
                            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5"><tr><th class="px-3 py-3">Reference</th><th class="px-3 py-3">Date</th><th class="px-3 py-3">Narration</th><th class="px-3 py-3">Status</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @forelse ($recentJournals as $journal)
                                    @php
                                        $statusLabel = $journal->reversalOf ? 'Reversal' : ($journal->reversal ? 'Reversed' : ucfirst($journal->status->value));
                                        $statusTone = $statusLabel === 'Posted' ? 'success' : ($statusLabel === 'Draft' ? 'warning' : 'gray');
                                    @endphp
                                    <tr><td class="whitespace-nowrap px-3 py-3 font-medium">{{ $journal->reference }}</td><td class="whitespace-nowrap px-3 py-3">{{ $journal->posting_date?->format('d M Y') }}</td><td class="max-w-sm px-3 py-3">{{ $journal->narration }}</td><td class="whitespace-nowrap px-3 py-3"><x-accounting.status-badge :status="$statusLabel" :tone="$statusTone" /></td></tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-8"><x-accounting.empty-state title="No journal activity yet" description="Saved journal drafts and posted journal activity will appear here." /></td></tr>
                                @endforelse
                            </tbody>
                        </x-accounting.data-table>
                    @else
                        <x-accounting.empty-state title="Journal activity is permission controlled" description="You do not have access to General Journal history." />
                    @endif
                </x-filament::section>
            </div>
        @endif
    </div>
</x-filament-panels::page>
