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
        .tpz-sr-view { color: var(--fi-color-gray-950, #142b3d); font-size: .875rem; }
        .tpz-sr-view .tpz-sr-title { display:flex; align-items:center; gap:.6rem; margin:0 0 .8rem; padding:.68rem 1rem; border-radius:.85rem; font-size:1.06rem; font-weight:750; color:#0b5663; background:linear-gradient(90deg,#c8f4ef,#e1f7f5); }
        .tpz-sr-view .tpz-sr-box { background:#fff; border:1px solid #e1e9ed; border-radius:1rem; padding:1rem 1.2rem; box-shadow:0 2px 9px rgba(16,47,66,.045); }
        .tpz-sr-view .tpz-sr-meta { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem 1.25rem; }
        .tpz-sr-view .tpz-sr-meta-item { min-width:0; padding-right:.8rem; border-right:1px solid #e0e8ec; }
        .tpz-sr-view .tpz-sr-meta-item:nth-child(3n) { border-right:0; }
        .tpz-sr-view .tpz-sr-label { font-size:.74rem; font-weight:650; color:#53677a; margin-bottom:.25rem; }
        .tpz-sr-view .tpz-sr-value { font-weight:650; color:#193449; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.85rem; margin:1rem 0 1.25rem; }
        .tpz-sr-view .tpz-sr-kpi { border:1px solid #dbeaf0; border-radius:1rem; padding:.85rem 1rem; }
        .tpz-sr-view .tpz-sr-kpi strong { display:block; font-size:1.7rem; line-height:1.15; margin-top:.3rem; font-variant-numeric:tabular-nums; }
        .tpz-sr-view .tpz-sr-kpi--requested { background:#eaf9fd; color:#13647b; }
        .tpz-sr-view .tpz-sr-kpi--approved { background:#effbf1; color:#1f7a45; }
        .tpz-sr-view .tpz-sr-kpi--transferred { background:#f4f0fe; color:#6241a4; }
        .tpz-sr-view .tpz-sr-kpi--remaining { background:#fff7e7; color:#a56b0f; }
        .tpz-sr-view .tpz-sr-items { display:grid; gap:.8rem; }
        .tpz-sr-view .tpz-sr-item { display:grid; grid-template-columns:minmax(0,1.3fr) minmax(0,1.2fr) minmax(0,1.3fr); gap:1rem; align-items:start; }
        .tpz-sr-view .tpz-sr-sku { display:inline-block; background:#dff5f7; border-radius:.4rem; padding:.16rem .45rem; color:#126777; font-weight:750; font-size:.76rem; }
        .tpz-sr-view .tpz-sr-product { margin-top:.4rem; line-height:1.45; overflow-wrap:anywhere; font-weight:580; }
        .tpz-sr-view .tpz-sr-warehouse { font-weight:650; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-quantities { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.35rem; margin-top:.7rem; }
        .tpz-sr-view .tpz-sr-qty { text-align:center; padding:.38rem .2rem; border-radius:.4rem; background:#edf7fe; font-variant-numeric:tabular-nums; }
        .tpz-sr-view .tpz-sr-qty:nth-child(2) { background:#e9f9ec; }
        .tpz-sr-view .tpz-sr-qty:nth-child(3) { background:#f1ebfd; }
        .tpz-sr-view .tpz-sr-qty:nth-child(4) { background:#fff6e8; }
        .tpz-sr-view .tpz-sr-qty small { display:block; font-size:.64rem; color:#53677a; }
        .tpz-sr-view .tpz-sr-qty strong { font-size:1.05rem; }
        .tpz-sr-view .tpz-sr-source-title { background:#eaf9f9; color:#215d68; font-weight:750; padding:.4rem .55rem; border-radius:.4rem; margin-bottom:.45rem; }
        .tpz-sr-view .tpz-sr-source { padding:.65rem 0; border-top:1px solid #ebeff3; }
        .tpz-sr-view .tpz-sr-source:first-of-type { border-top:0; }
        .tpz-sr-view .tpz-sr-source-grid { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.25rem .8rem; }
        .tpz-sr-view .tpz-sr-source-label { color:#53677a; font-size:.7rem; }
        .tpz-sr-view .tpz-sr-source-value { font-weight:650; overflow-wrap:anywhere; }
        .tpz-sr-view .tpz-sr-badge { display:inline-flex; padding:.23rem .55rem; border-radius:.45rem; font-weight:700; font-size:.73rem; background:#fff4d7; color:#94621e; }
        .tpz-sr-view .tpz-sr-badge--approved { background:#ddf8e5; color:#1b7d44; }
        .tpz-sr-view .tpz-sr-badge--rejected { background:#fee5e6; color:#ba2c37; }
        .tpz-sr-view .tpz-sr-badge--completed { background:#e4f0fc; color:#255f9c; }
        .tpz-sr-view .tpz-sr-actions { display:flex; gap:.45rem; flex-wrap:wrap; margin-top:.6rem; }
        .tpz-sr-view .tpz-sr-action { border:0; border-radius:.48rem; padding:.46rem .75rem; color:white; font-size:.77rem; font-weight:750; cursor:pointer; }
        .tpz-sr-view .tpz-sr-action--approve { background:#12a444; }
        .tpz-sr-view .tpz-sr-action--reject { background:#d82631; }
        .tpz-sr-view .tpz-sr-note { margin-top:.45rem; color:#53677a; font-size:.75rem; overflow-wrap:anywhere; }
        .dark .tpz-sr-view .tpz-sr-box { background:#192638; border-color:#314258; }
        .dark .tpz-sr-view .tpz-sr-value, .dark .tpz-sr-view .tpz-sr-product, .dark .tpz-sr-view .tpz-sr-warehouse { color:#e5eef7; }
        .dark .tpz-sr-view .tpz-sr-label { color:#a7b8c9; }
        @media(max-width:1100px) { .tpz-sr-view .tpz-sr-item { grid-template-columns:repeat(2,minmax(0,1fr)); } .tpz-sr-view .tpz-sr-source-section { grid-column:1/-1; } }
        @media(max-width:700px) { .tpz-sr-view .tpz-sr-meta { grid-template-columns:repeat(2,minmax(0,1fr)); } .tpz-sr-view .tpz-sr-meta-item { border:0; } .tpz-sr-view .tpz-sr-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .tpz-sr-view .tpz-sr-item { grid-template-columns:1fr; } .tpz-sr-view .tpz-sr-source-section { grid-column:auto; } }
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
                                <div><div class="tpz-sr-source-label">Proposed Qty</div><div class="tpz-sr-source-value">{{ (int) $source->proposed_quantity }}</div></div>
                                <div><div class="tpz-sr-source-label">Approval Route</div><div class="tpz-sr-source-value">{{ $source->requiresOwnerAdminApproval() ? 'Owner/Admin' : 'Allocation Holder' }}</div></div>
                                <div><div class="tpz-sr-source-label">Status</div><span class="tpz-sr-badge tpz-sr-badge--{{ $source->status?->value }}">{{ $source->status?->getLabel() ?? 'Unknown' }}</span></div>
                            </div>
                            @if((int)$source->approved_quantity || (int)$source->transferred_quantity)
                                <div class="tpz-sr-note">Approved: {{ (int) $source->approved_quantity }} · Transferred: {{ (int) $source->transferred_quantity }}</div>
                            @endif
                            @if($source->decision_note)
                                <div class="tpz-sr-note">Note: {{ $source->decision_note }}</div>
                            @endif
                            @if(in_array($source->id, $eligibleIds, true))
                                <div class="tpz-sr-actions">
                                    <button type="button" class="tpz-sr-action tpz-sr-action--approve" wire:click="mountAction('approveSource', { source_line_id: {{ (int)$source->id }} })">✓ Approve</button>
                                    <button type="button" class="tpz-sr-action tpz-sr-action--reject" wire:click="mountAction('rejectSource', { source_line_id: {{ (int)$source->id }} })">✕ Reject</button>
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