<x-accounting.workspace current="journal" title="General Journal" description="Prepare balanced, period-aware journal drafts. Posting remains disabled." :books="$books" :book="$book">
    <div class="space-y-6">
        @if (! $book)
            <x-accounting.empty-state title="No accounting book is configured" description="A B1 accounting book is required before journal drafts can be prepared." />
        @elseif (! $canCreateJournal)
            <x-accounting.empty-state title="Draft creation is not available to your role" description="You can review journal history, but creating drafts requires the separate Create Journal permission." />
        @else
            <x-filament::section heading="New journal draft" :description="$book->name.' · '.$book->currency.' · Posting is disabled'" compact>
                @if ($accounts->isEmpty())
                    <x-accounting.empty-state title="No eligible posting accounts" description="Choose active, non-control posting accounts in the selected book before creating a journal draft." />
                @else
                    <form wire:submit="saveDraft" x-on:keydown.alt.enter.prevent="$wire.addLine()" class="space-y-5">
                        <div class="tpz-accounting-document-header"><div><small>Reference</small><strong>Assigned when saved</strong></div><div><small>Accounting period</small><strong>{{ $period?->name ?? 'No matching period' }}</strong></div><div><small>Book / currency</small><strong>{{ $book->name }} · {{ $book->currency }}</strong></div></div>
                        <div class="grid gap-4 md:grid-cols-3">
                            <label class="grid content-start gap-1 text-sm font-medium"><span>Posting date</span><x-filament::input.wrapper><x-filament::input type="date" wire:model="postingDate" aria-describedby="journal-date-help" /></x-filament::input.wrapper><span id="journal-date-help" class="text-xs font-normal text-gray-500">Must fall within an open period.</span>@error('postingDate')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</label>
                            <label class="grid content-start gap-1 text-sm font-medium md:col-span-2"><span>Narration</span><x-filament::input.wrapper><x-filament::input wire:model="narration" maxlength="500" placeholder="Describe the purpose of this adjustment" />@error('narration')<span class="text-xs text-danger-600">{{ $message }}</span>@enderror</x-filament::input.wrapper></label>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div><h3 class="text-sm font-semibold">Journal lines</h3><p class="text-xs text-gray-500">Use Tab to move between cells. Press Alt+Enter to add a line.</p></div>
                            <x-filament::button type="button" color="gray" icon="heroicon-m-plus" wire:click="addLine">Add line</x-filament::button>
                        </div>

                        <x-accounting.transaction-grid label="General journal lines" minimum-width="68rem">
                            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5"><tr><th scope="col" class="px-3 py-3">Account</th><th scope="col" class="px-3 py-3">Description</th><th scope="col" class="px-3 py-3 text-right">Debit · {{ $book->currency }}</th><th scope="col" class="px-3 py-3 text-right">Credit · {{ $book->currency }}</th><th scope="col" class="px-3 py-3"><span class="sr-only">Remove line</span></th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($lines as $index => $line)
                                    <tr wire:key="journal-line-{{ $index }}">
                                        <td class="w-72 px-2 py-2"><label class="sr-only" for="journal-account-{{ $index }}">Account for line {{ $index + 1 }}</label><x-filament::input.wrapper><x-filament::input.select id="journal-account-{{ $index }}" wire:model="lines.{{ $index }}.account_id"><option value="">Select account…</option>@foreach ($accounts as $account)<option value="{{ $account->id }}">{{ $account->number }} — {{ $account->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></td>
                                        <td class="min-w-56 px-2 py-2"><label class="sr-only" for="journal-description-{{ $index }}">Description for line {{ $index + 1 }}</label><x-filament::input.wrapper><x-filament::input id="journal-description-{{ $index }}" wire:model="lines.{{ $index }}.description" maxlength="250" placeholder="Line memo (optional)" /></x-filament::input.wrapper></td>
                                        <td class="w-44 px-2 py-2"><label class="sr-only" for="journal-debit-{{ $index }}">Debit for line {{ $index + 1 }}</label><x-filament::input.wrapper><x-filament::input id="journal-debit-{{ $index }}" wire:model.live.debounce.250ms="lines.{{ $index }}.debit" inputmode="decimal" placeholder="0.00" class="text-right tabular-nums" /></x-filament::input.wrapper></td>
                                        <td class="w-44 px-2 py-2"><label class="sr-only" for="journal-credit-{{ $index }}">Credit for line {{ $index + 1 }}</label><x-filament::input.wrapper><x-filament::input id="journal-credit-{{ $index }}" wire:model.live.debounce.250ms="lines.{{ $index }}.credit" inputmode="decimal" placeholder="0.00" class="text-right tabular-nums" /></x-filament::input.wrapper></td>
                                        <td class="px-2 py-2 text-center"><x-filament::icon-button icon="heroicon-m-trash" color="gray" label="Remove line {{ $index + 1 }}" wire:click="removeLine({{ $index }})" :disabled="count($lines) <= 2" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t-2 border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5"><tr><th scope="row" colspan="2" class="px-3 py-3 text-right">Draft totals</th><td class="px-3 py-3 text-right tabular-nums">{{ $debitTotal }} {{ $book->currency }}</td><td class="px-3 py-3 text-right tabular-nums">{{ $creditTotal }} {{ $book->currency }}</td><td></td></tr></tfoot>
                        </x-accounting.transaction-grid>

                        @error('lines')<x-filament::section icon="heroicon-m-exclamation-circle" icon-color="danger" compact><p class="text-sm text-danger-700 dark:text-danger-300">{{ $message }}</p></x-filament::section>@enderror
                        @if ($invalidAmount)<p role="status" class="text-sm text-danger-600">Enter non-negative amounts with no more than two decimal places.</p>
                        @elseif ($isBalancedPreview)<p role="status" class="text-sm text-success-600">Debits and credits currently balance. Saving creates a draft only.</p>
                        @else<p role="status" class="text-sm text-warning-600">Draft is not balanced yet. Enter matching, positive debit and credit totals before saving.</p>@endif
                        @if ($book->posting_enabled && config('accounting.posting_activation_approved', false) !== true)<p class="text-sm text-warning-600">The book flag is enabled, but posting activation approval remains off; journals cannot be posted.</p>@endif

                        <div class="flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 dark:border-white/10 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs text-gray-500">Save Draft validates accounts, balance and accounting period. No ledger posting occurs.</p>
                            <x-filament::button type="submit" icon="heroicon-m-document-plus" wire:loading.attr="disabled" wire:target="saveDraft"><span wire:loading.remove wire:target="saveDraft">Save Draft</span><span wire:loading wire:target="saveDraft">Saving…</span></x-filament::button>
                        </div>
                    </form>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Journal history" description="Drafts remain unposted. Posted journals are immutable; corrections use linked reversals." compact>
            @if ($journals->isNotEmpty())
                <x-accounting.data-table minimum-width="66rem">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/5"><tr><th scope="col" class="px-4 py-3">Reference</th><th scope="col" class="px-4 py-3">Posting date</th><th scope="col" class="px-4 py-3">Period</th><th scope="col" class="px-4 py-3">Narration</th><th scope="col" class="px-4 py-3">Status</th><th scope="col" class="px-4 py-3">Linked record</th><th scope="col" class="px-4 py-3">Total</th><th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($journals as $journal)
                            @php
                                $statusLabel = $journal->reversalOf ? 'Reversal' : ($journal->reversal ? 'Reversed' : ucfirst($journal->status->value));
                                $statusTone = $statusLabel === 'Posted' || $statusLabel === 'Reversal' ? 'success' : ($statusLabel === 'Draft' ? 'warning' : 'gray');
                                $journalTotal = $journal->lines->reduce(fn (string $total, $line): string => bcadd($total, (string) $line->debit, 2), '0.00');
                            @endphp
                            <tr class="hover:bg-gray-50/80 dark:hover:bg-white/[0.03]">
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ $journal->reference }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $journal->posting_date?->format('d M Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $journal->period?->name ?? '—' }}</td>
                                <td class="max-w-sm px-4 py-3">{{ $journal->narration }}</td>
                                <td class="whitespace-nowrap px-4 py-3"><x-accounting.status-badge :status="$statusLabel" :tone="$statusTone" /></td>
                                <td class="whitespace-nowrap px-4 py-3">@if ($journal->reversalOf) Reverses {{ $journal->reversalOf->reference }} @elseif ($journal->reversal) Reversed by {{ $journal->reversal->reference }} @else — @endif</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $journalTotal }} {{ $book?->currency }}</td>
                                <td class="whitespace-nowrap px-4 py-3">@if ($journal->status->value === 'draft') @if ($canPostJournal)<x-filament::button size="xs" color="warning" wire:click="postJournal({{ $journal->id }})" wire:confirm="Post this journal? Posted journals are immutable.">Post</x-filament::button>@else<x-accounting.status-badge status="Posting disabled" tone="gray" />@endif @else<span class="text-gray-400">—</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-accounting.data-table>
            @else
                <x-accounting.empty-state title="No journal history" description="Saved drafts and posted entries for this book will appear here." />
            @endif
            @if (! $canPostJournal)
                <p class="mt-3 text-xs text-gray-500">Journal posting is disabled for this environment. The protected B1 posting service remains unchanged.</p>
            @endif
        </x-filament::section>
    </div>
</x-accounting.workspace>
