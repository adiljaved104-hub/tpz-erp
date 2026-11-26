@php
    /** @var \App\Models\StockRequest $request */
    $request = $getRecord();
    $actor = auth()->user();
    $stockService = app(\App\Services\Inventory\StockRequestService::class);
    $approvalService = app(\App\Services\Inventory\StockRequestApprovalService::class);
    $items = $actor instanceof \App\Models\User ? $stockService->visibleItems($actor, $request) : collect();
    $eligibleIds = $actor instanceof \App\Models\User
        ? $approvalService->eligiblePendingLines($request, $actor)->pluck('id')->all()
        : [];
    $totalRequested = (int) $items->sum('quantity');
    $totalApproved = (int) $items->sum(fn ($item) => $item->sourceLines->sum('approved_quantity'));
    $totalTransferred = (int) $items->sum(fn ($item) => $item->sourceLines->sum('transferred_quantity'));
    $totalRemaining = max(0, $totalRequested - $totalTransferred);
    $requestDate = app(\App\Services\UserTimezoneResolver::class)->format($request->created_at, 'd M Y, h:i A');
    $canSeeExecution = $actor instanceof \App\Models\User
        && ($request->requested_by_employee_id === $actor->employee?->id
            || ! app(\App\Services\Responsibilities\ResponsibilityProductScopeService::class)->requiresScope($actor));
    $execution = $canSeeExecution ? $request->execution : null;
@endphp
<div class="tpz-sr-view">
    <style>
        /* Match TPZ's compact navy/blue dashboard cards; styles are view scoped. */
        .tpz-sr-view { color:#172b45; font-size:.85rem; --tpz-line:#dce6f2; --tpz-accent:#116bb3; }
        .tpz-sr-view .tpz-sr-title { display:flex; align-items:center; gap:.55rem; margin:0 0 .7rem; padding:.6rem .9rem; border-radius:.7rem; font-size:1rem; font-weight:750; color:#123957; background:linear-gradient(110deg,#e9f3ff,#f5f9ff); border:1px solid #dae8f8; }
        .tpz-sr-view .tpz-sr-box { background:#fff; border:1px solid var(--tpz-line); border-radius:.8rem; padding:.85rem 1rem; box-shadow:0 2px 9px rgba(18,52,91,.045); }
        .tpz-sr-view .tpz-sr-meta { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.75rem 1rem; }
        .tpz-sr-view .tpz-sr-meta-item { min-width:0; padding-right:.8rem; border-right:1px solid #e8edf5; }
        .tpz-sr-view .tpz-sr-meta-item:nth-child(3n) { border-right:0; }
        .tpz-sr-view .tpz-sr-label { font-size:.71rem; font-weight:650; color:#63748b; margin-bottom:.2rem; }
        .tpz-sr-view .tpz-sr-value { font-weight:650; color:#183b60; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.7rem; margin:.8rem 0 1rem; }
        .tpz-sr-view .tpz-sr-kpi { border:1px solid #dae7f5; border-radius:.75rem; padding:.7rem .9rem; background:#f5f9ff; color:#18456d; }
        .tpz-sr-view .tpz-sr-kpi strong { display:block; font-size:1.55rem; line-height:1.16; margin-top:.24rem; font-variant-numeric:tabular-nums; }
        .tpz-sr-view .tpz-sr-kpi--approved { background:#eef8f4; color:#176d50; }
        .tpz-sr-view .tpz-sr-kpi--transferred { background:#f1f4ff; color:#435aa0; }
        .tpz-sr-view .tpz-sr-kpi--remaining { background:#fff8ed; color:#99621c; }
        .tpz-sr-view .tpz-sr-items { display:grid; gap:.65rem; }
        /* Product | warehouse/quantities | stock-source + status/actions, all horizontal on desktop. */
        .tpz-sr-view .tpz-sr-item { display:grid; grid-template-columns:minmax(0,1.08fr) minmax(0,1.02fr) minmax(0,1.56fr); gap:.8rem; align-items:start; }
        .tpz-sr-view .tpz-sr-item > div { min-width:0; }
        .tpz-sr-view .tpz-sr-item > div + div { border-left:1px solid #e5ecf5; padding-left:.8rem; }
        .tpz-sr-view .tpz-sr-sku { display:inline-block; background:#e6f3ff; border-radius:.35rem; padding:.12rem .4rem; color:#1267a6; font-weight:750; font-size:.72rem; }
        .tpz-sr-view .tpz-sr-product { margin-top:.38rem; line-height:1.34; overflow-wrap:anywhere; font-weight:560; color:#193d61; }
        .tpz-sr-view .tpz-sr-warehouse { font-weight:650; color:#193d61; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-quantities { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.25rem; margin-top:.5rem; }
        .tpz-sr-view .tpz-sr-qty { text-align:center; padding:.32rem .12rem; border:1px solid #e2ebf7; border-radius:.35rem; background:#eff6fe; font-variant-numeric:tabular-nums; min-width:0; }
        .tpz-sr-view .tpz-sr-qty:nth-child(2) { background:#effaf3; border-color:#d9efe2; }
        .tpz-sr-view .tpz-sr-qty:nth-child(3) { background:#f2f3ff; border-color:#e2e5f9; }
        .tpz-sr-view .tpz-sr-qty:nth-child(4) { background:#fff7ec; border-color:#f3ead9; }
        .tpz-sr-view .tpz-sr-qty small { display:block; font-size:.59rem; color:#536f8b; white-space:normal; }
        .tpz-sr-view .tpz-sr-qty strong { font-size:.98rem; }
        .tpz-sr-view .tpz-sr-source-title { background:#ecf5ff; color:#184773; font-weight:750; padding:.35rem .55rem; border-radius:.38rem; margin-bottom:.3rem; font-size:.78rem; }
        .tpz-sr-view .tpz-sr-source { display:grid; grid-template-columns:minmax(0,1fr) minmax(135px,.52fr); gap:.6rem; padding:.46rem 0; border-top:1px solid #e6edf6; align-items:center; }
        .tpz-sr-view .tpz-sr-source:first-of-type { border-top:0; }
        .tpz-sr-view .tpz-sr-source-grid { display:grid; grid-template-columns:minmax(0,1.35fr) minmax(0,.4fr) minmax(0,1fr); gap:.35rem; align-items:start; }
        .tpz-sr-view .tpz-sr-source-grid > div { min-width:0; }
        .tpz-sr-view .tpz-sr-source-label { color:#60748b; font-size:.64rem; }
        .tpz-sr-view .tpz-sr-source-value { font-weight:650; overflow-wrap:anywhere; color:#193d61; font-size:.75rem; }
        .tpz-sr-view .tpz-sr-badge { display:inline-flex; padding:.18rem .42rem; border-radius:.36rem; font-weight:700; font-size:.69rem; background:#fff3df; color:#97621c; }
        .tpz-sr-view .tpz-sr-badge--approved { background:#e1f7eb; color:#197248; }
        .tpz-sr-view .tpz-sr-badge--rejected { background:#fde9ec; color:#bd3545; }
        .tpz-sr-view .tpz-sr-badge--completed { background:#e3f0ff; color:#245f97; }
        .tpz-sr-view .tpz-sr-source-decision { display:flex; flex-direction:column; align-items:flex-start; gap:.28rem; min-width:0; }
        .tpz-sr-view .tpz-sr-actions { display:flex; flex-wrap:wrap; gap:.3rem; margin:0; }
        .tpz-sr-view .tpz-sr-action { border:0; border-radius:.4rem; padding:.4rem .52rem; color:#fff; font-size:.72rem; font-weight:750; cursor:pointer; white-space:nowrap; }
        .tpz-sr-view .tpz-sr-action--approve { background:#16875c; }
        .tpz-sr-view .tpz-sr-action--reject { background:#c73c49; }
        .tpz-sr-view .tpz-sr-note { color:#687b91; font-size:.69rem; line-height:1.3; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-source-foot { grid-column:1/-1; }
        .dark .tpz-sr-view .tpz-sr-box { background:#172438; border-color:#334761; }
        .dark .tpz-sr-view .tpz-sr-value, .dark .tpz-sr-view .tpz-sr-product, .dark .tpz-sr-view .tpz-sr-warehouse, .dark .tpz-sr-view .tpz-sr-source-value { color:#e1edf9; }
        .dark .tpz-sr-view .tpz-sr-title, .dark .tpz-sr-view .tpz-sr-source-title { background:#203a5a; color:#e0eeff; border-color:#385778; }
        @media(max-width:1080px) { .tpz-sr-view .tpz-sr-item { grid-template-columns:minmax(0,1fr) minmax(0,1fr); } .tpz-sr-view .tpz-sr-source-section { grid-column:1/-1; border-left:0 !important; padding-left:0 !important; border-top:1px solid #e5ecf5; padding-top:.55rem; } }
        @media(max-width:650px) { .tpz-sr-view .tpz-sr-meta { grid-template-columns:repeat(2,minmax(0,1fr)); } .tpz-sr-view .tpz-sr-meta-item { border:0; } .tpz-sr-view .tpz-sr-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .tpz-sr-view .tpz-sr-item { grid-template-columns:1fr; } .tpz-sr-view .tpz-sr-item > div + div { border:0; padding:0; } .tpz-sr-view .tpz-sr-source-section { grid-column:auto; } .tpz-sr-view .tpz-sr-source { grid-template-columns:1fr; } .tpz-sr-view .tpz-sr-source-grid { grid-template-columns:minmax(0,1.4fr) minmax(0,.6fr) minmax(0,1fr); } }
    </style>

    <div class="tpz-sr-title">▣ &nbsp;Stock Request</div>
    <section class="tpz-sr-box tpz-sr-meta" aria-label="Stock request details">
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Reference</div><div class="tpz-sr-value">{{ $request->reference }}</div></div>
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Purpose</div><div class="tpz-sr-value">{{ $request->purpose?->getLabel() ?? '—' }}</div></div>
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Requested By</div><div class="tpz-sr-value">{{ $request->requester?->name ?? '—' }}</div></div>
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Requested At</div><div class="tpz-sr-value">{{ $requestDate ?? '—' }}</div></div>
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Reason</div><div class="tpz-sr-value">{{ $request->reason ?: '—' }}</div></div>
        <div class="tpz-sr-meta-item"><div class="tpz-sr-label">Status</div><span class="tpz-sr-badge tpz-sr-badge--{{ $request->status?->value === 'rejected' ? 'rejected' : ($request->status?->value === 'approved' ? 'approved' : '') }}">{{ $request->status?->getLabel() ?? 'Unknown' }}</span></div>
        @if($request->order)<div class="tpz-sr-meta-item"><div class="tpz-sr-label">Order</div><div class="tpz-sr-value">{{ $request->order->reference }}</div></div>@endif
    </section>

    <div class="tpz-sr-kpis" aria-label="Stock request quantity summary">
        <div class="tpz-sr-kpi tpz-sr-kpi--requested"><span>Total Requested Qty</span><strong>{{ number_format($totalRequested) }}</strong></div>
        <div class="tpz-sr-kpi tpz-sr-kpi--approved"><span>Total Approved Qty</span><strong>{{ number_format($totalApproved) }}</strong></div>
        <div class="tpz-sr-kpi tpz-sr-kpi--transferred"><span>Total Transferred Qty</span><strong>{{ number_format($totalTransferred) }}</strong></div>
        <div class="tpz-sr-kpi tpz-sr-kpi--remaining"><span>Total Remaining Qty</span><strong>{{ number_format($totalRemaining) }}</strong></div>
    </div>

    <div class="tpz-sr-title">▣ &nbsp;Requested Products ({{ $items->count() }})</div>
    <div class="tpz-sr-items">
        @forelse($items as $item)
            <article class="tpz-sr-box tpz-sr-item" wire:key="stock-request-card-{{ $item->id }}">
                <div>
                    <span class="tpz-sr-sku">SKU: {{ $item->sku }}</span>
                    <div class="tpz-sr-product">{{ $item->product_name }}</div>
                </div>
                <div>
                    <div class="tpz-sr-label">Warehouse</div>
                    <div class="tpz-sr-warehouse">{{ $item->warehouse_name }}</div>
                    <div class="tpz-sr-quantities">
                        <div class="tpz-sr-qty"><small>Requested</small><strong>{{ (int) $item->quantity }}</strong></div>
                        <div class="tpz-sr-qty"><small>Approved</small><strong>{{ (int) $item->sourceLines->sum('approved_quantity') }}</strong></div>
                        <div class="tpz-sr-qty"><small>Transferred</small><strong>{{ (int) $item->sourceLines->sum('transferred_quantity') }}</strong></div>
                        <div class="tpz-sr-qty"><small>Remaining</small><strong>{{ max(0, (int) $item->quantity - (int) $item->sourceLines->sum('transferred_quantity')) }}</strong></div>
                    </div>
                </div>
                <div class="tpz-sr-source-section">
                    <div class="tpz-sr-source-title">Available Sources / Approval Status</div>
                    @forelse($item->sourceLines as $source)
                        <div class="tpz-sr-source" wire:key="stock-request-source-card-{{ $source->id }}">
                            <div class="tpz-sr-source-grid">
                                <div><div class="tpz-sr-source-label">Source</div><div class="tpz-sr-source-value">{{ $source->source_label }}</div></div>
                                <div><div class="tpz-sr-source-label">Proposed</div><div class="tpz-sr-source-value">{{ (int) $source->proposed_quantity }}</div></div>
                                <div><div class="tpz-sr-source-label">Approval Route</div><div class="tpz-sr-source-value">{{ $source->requiresOwnerAdminApproval() ? 'Owner/Admin' : 'Allocation Holder' }}</div></div>
                            </div>
                            <div class="tpz-sr-source-decision">
                                <div class="tpz-sr-source-label">Status</div>
                                <span class="tpz-sr-badge tpz-sr-badge--{{ $source->status?->value }}">{{ $source->status?->getLabel() ?? 'Unknown' }}</span>
                                @if(in_array($source->id, $eligibleIds, true))
                                    <div class="tpz-sr-actions">
                                        <button type="button" class="tpz-sr-action tpz-sr-action--approve" wire:click="mountAction('approveSource', { source_line_id: {{ (int)$source->id }} })">✓ Approve</button>
                                        <button type="button" class="tpz-sr-action tpz-sr-action--reject" wire:click="mountAction('rejectSource', { source_line_id: {{ (int)$source->id }} })">✕ Reject</button>
                                    </div>
                                @endif
                            </div>
                            @if((int)$source->approved_quantity || (int)$source->transferred_quantity || $source->decision_note)
                                <div class="tpz-sr-source-foot tpz-sr-note">
                                    @if((int)$source->approved_quantity || (int)$source->transferred_quantity)
                                        Approved: {{ (int) $source->approved_quantity }} · Transferred: {{ (int) $source->transferred_quantity }}
                                    @endif
                                    @if($source->decision_note)
                                        <span>Note: {{ $source->decision_note }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="tpz-sr-note">No proposed stock sources.</p>
                    @endforelse
                </div>
            </article>
        @empty
            <div class="tpz-sr-box">No requested products in your authorized scope.</div>
        @endforelse
    </div>

    @if($execution)
        <div class="tpz-sr-title" style="margin-top:1rem">▣ &nbsp;Execution</div>
        <section class="tpz-sr-box">
            <div class="tpz-sr-meta">
                <div><div class="tpz-sr-label">Execution Type</div><div class="tpz-sr-value">{{ str($execution->execution_type)->headline() }}</div></div>
                <div><div class="tpz-sr-label">Executed At</div><div class="tpz-sr-value">{{ app(\App\Services\UserTimezoneResolver::class)->format($execution->executed_at, 'd M Y, h:i A') }}</div></div>
                <div><div class="tpz-sr-label">Executed By</div><div class="tpz-sr-value">{{ $execution->executedBy?->employee?->name ?? '—' }}</div></div>
            </div>
            @foreach($execution->lines as $line)
                <div class="tpz-sr-note">{{ $line->item?->sku }} · {{ (int)$line->quantity }} unit(s) · {{ $line->sourceAccount?->name ?? 'System / Unassigned' }} → {{ $line->destinationAccount?->name ?? '—' }}</div>
            @endforeach
        </section>
    @endif
</div>