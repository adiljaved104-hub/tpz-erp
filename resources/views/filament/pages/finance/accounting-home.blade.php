<x-accounting.workspace current="home" title="Accounting Home" description="Your accounting workspace · verified TPZ ERP records only" :books="$books" :book="$book">
    @if (! $book)
        <div class="tpz-accounting-disabled">No Accounting Book is configured. Contact an administrator to complete the B1 setup.</div>
    @else
        <div class="tpz-accounting-notice">Customer receivables, supplier payables, bank feeds and inventory accounting are not connected. Their balances are unavailable. Journal posting remains disabled.</div>
        <section class="tpz-accounting-dashboard-cards" aria-label="Accounting summary">
            <div class="tpz-accounting-dashboard-card"><label>Cash and bank</label><strong>Unavailable</strong><small>Banking integration is not connected</small></div>
            <div class="tpz-accounting-dashboard-card"><label>Open receivables</label><strong>Unavailable</strong><small>Customer AR integration is not connected</small></div>
            <div class="tpz-accounting-dashboard-card"><label>Open payables</label><strong>Unavailable</strong><small>Supplier AP integration is not connected</small></div>
            <div class="tpz-accounting-dashboard-card"><label>Accounting book</label><strong>{{ $book->name }}</strong><small>{{ $book->currency }} · {{ $accountCount === null ? 'Account count restricted' : number_format($accountCount).' chart accounts' }}</small></div>
        </section>
        <section class="tpz-accounting-panels">
            <div class="tpz-accounting-panel">
                <h2>Work queue</h2>
                <div class="tpz-accounting-activity-row"><span>Journal posting</span><span class="tpz-accounting-disabled">Disabled pending Owner approval</span></div>
                <div class="tpz-accounting-activity-row"><span>Selected period</span><span>{{ $period?->name ?? 'No period selected' }}</span></div>
                <div class="tpz-accounting-activity-row"><span>Customer and vendor workflows</span><span class="tpz-accounting-disabled">Not connected</span></div>
                <div class="tpz-accounting-activity-row"><span>Banking and reconciliation</span><span class="tpz-accounting-disabled">Not connected</span></div>
            </div>
            <div class="tpz-accounting-panel">
                <h2>Quick access</h2>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($navigationLinks as $link)
                        <a class="fi-btn fi-btn-size-md fi-btn-color-primary fi-btn-outlined" href="{{ $link['url'] }}">{{ $link['label'] }} →</a>
                    @endforeach
                </div>
                <h2 class="mt-6">Recent accounting activity</h2>
                @if ($canViewJournals)
                    @forelse ($recentJournals as $journal)
                        @php($statusLabel = $journal->reversalOf ? 'Reversal' : ($journal->reversal ? 'Reversed' : ucfirst($journal->status->value)))
                        <div class="tpz-accounting-activity-row"><span><strong>{{ $journal->reference }}</strong> · {{ $journal->narration }}</span><span>{{ $journal->posting_date?->format('d M Y') }} · {{ $statusLabel }}</span></div>
                    @empty
                        <div class="tpz-accounting-disabled">No journal records exist for this book yet.</div>
                    @endforelse
                @else
                    <div class="tpz-accounting-disabled">Journal activity is hidden because you do not have General Journal view permission.</div>
                @endif
            </div>
        </section>
        <section class="tpz-accounting-panel" style="margin-top:14px">
            <h2>Operational integrations</h2>
            <div class="tpz-accounting-dashboard-cards">
                <div class="tpz-accounting-disabled">Purchasing · integration not yet verified</div><div class="tpz-accounting-disabled">Inventory · integration not yet verified</div><div class="tpz-accounting-disabled">Sales and returns · integration not yet verified</div><div class="tpz-accounting-disabled">Financial reports · available B1 reports only</div>
            </div>
        </section>
    @endif
</x-accounting.workspace>
