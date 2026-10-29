@php
    $return = $getRecord();
    $allowed = $return && auth()->user() && app(\App\Services\Authorization\CustomerReturnAuthorization::class)
        ->allows(auth()->user(), \App\Enums\CustomerReturnPermission::View, $return);
    $items = $allowed ? $return->items()->with(['inspections.inspectedBy', 'inspections.damagedStockEvent', 'findings.evidence', 'claim', 'quarantine', 'exceptionCases.events.actor', 'exceptionCases.finding.recordedBy'])->get() : collect();
    if ($allowed) {
        $return->loadMissing('platform');
    }
@endphp

@if ($allowed)
    <div class="space-y-4">
        @forelse ($items as $item)
            @php
                $passed = (int) $item->inspections->where('result', \App\Enums\CustomerReturnInspectionResult::Sellable)->sum('quantity');
                $failed = (int) $item->inspections->where('result', \App\Enums\CustomerReturnInspectionResult::Damaged)->sum('quantity');
                $result = $passed > 0 && $failed > 0 ? 'Mixed' : ($failed > 0 ? 'FAIL' : ($passed > 0 ? 'PASS' : ($item->quarantine ? 'Wrong / Different Item' : 'Pending QC')));
                $evidenceReady = $item->findings->contains(fn ($finding) => $finding->evidence->isNotEmpty());
                $reviewReason = $item->qcEvidenceReviewReason();
                $damagedProblem = $item->inspections->first(fn ($inspection) => $inspection->result === \App\Enums\CustomerReturnInspectionResult::Damaged)?->problem_type;
                $claim = $item->claim;
                $case = $item->quarantine?->claim_case_reference;
                $claimReason = match (true) {
                    $claim !== null => $claim->reference.' · '.str($claim->status->value)->replace('_', ' ')->headline(),
                    $case !== null => $case.' · Wrong / Different Item case',
                    $item->quarantine !== null => 'No automatic marketplace case applies to this return.',
                    $failed === 0 => 'No failed original-product units; no damage claim is needed.',
                    $return->marketplace_platform_id === null => 'Not eligible: this order has no marketplace.',
                    $return->platform?->customer_return_claims_enabled !== true => 'Not eligible: automatic claims are not enabled for this marketplace.',
                    app(\App\Services\Claims\SafetClaimService::class)->eligible($item, $damagedProblem) => 'Eligible claim not found; Owner/Admin review is needed.',
                    default => 'Not eligible under the marketplace claim rules for this QC problem.',
                };
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-semibold text-gray-950 dark:text-white">{{ $item->sku_snapshot }} — {{ $item->product_name_snapshot }}</p>
                        <p class="text-sm text-gray-600 dark:text-gray-300">Returned {{ $item->return_quantity }} · PASS {{ $passed }} · FAIL {{ $failed }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-medium">
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $result }}</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ $evidenceReady ? 'Evidence Ready' : 'Evidence Pending' }}</span>
                    </div>
                </div>

                @if ($reviewReason)
                    <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">{{ $reviewReason }}</p>
                @endif

                @foreach ($item->exceptionCases as $case)
                    <div @class(['mt-3 rounded-lg p-3 text-sm', 'border border-rose-300 bg-rose-50 text-rose-950 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100' => $case->resolved_at === null, 'border border-gray-300 bg-gray-50 text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100' => $case->resolved_at !== null])>
                        <p class="font-semibold">{{ $case->resolved_at ? 'Resolved Return Exception' : ($case->kind === 'identity' ? 'Identity Review Pending' : 'QC Exception / Needs Review') }} · {{ str($case->status)->replace('_', ' ')->headline() }}</p>
                        <p>Original QC: {{ $case->original_qc_result ? str($case->original_qc_result)->headline() : 'Not completed' }} · New finding: {{ str($case->new_problem_type)->replace('_', ' ')->headline() }} · Quantity: {{ $case->affected_quantity }}</p>
                        <p>New evidence by {{ $case->finding->recordedBy?->name ?? 'Unknown' }} on {{ $case->finding->recorded_at?->format('d M Y, h:i A') ?? 'Date unavailable' }}.</p>
                        <p>Inventory protection: {{ str($case->protection_status)->replace('_', ' ')->headline() }}@if ($case->protection_reason) — {{ $case->protection_reason }}@endif</p>
                        @if ($case->resolved_at)<p>Owner/Admin decision: {{ str($case->resolution)->replace('_', ' ')->headline() }} · {{ $case->resolution_reason }}</p>@endif
                        <details class="mt-2"><summary class="cursor-pointer font-medium">Review history</summary>
                            <ul class="mt-1 space-y-1">@foreach ($case->events as $event)
                                <li>{{ str($event->action)->replace('_', ' ')->headline() }} · {{ $event->actor?->name ?? 'Unknown' }} · {{ $event->created_at?->format('d M Y, h:i A') }}</li>
                            @endforeach</ul>
                        </details>
                    </div>
                @endforeach

                <div class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                    @forelse ($item->inspections as $inspection)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <p class="font-medium">{{ $inspection->result === \App\Enums\CustomerReturnInspectionResult::Sellable ? 'PASS' : 'FAIL' }} · {{ $inspection->quantity }} {{ $inspection->quantity === 1 ? 'unit' : 'units' }}</p>
                            <p>Recorded problem: {{ $inspection->problem_type ? str($inspection->problem_type)->replace('_', ' ')->headline() : 'Not separately recorded' }}</p>
                            <p>QC remarks: {{ $inspection->notes ?: 'None recorded' }}</p>
                            <p>Inspected by: {{ $inspection->inspectedBy?->name ?? 'Unknown' }} · {{ $inspection->inspected_at?->format('d M Y, h:i A') ?? 'Date unavailable' }}</p>
                            <p>Inventory disposition: {{ $inspection->result === \App\Enums\CustomerReturnInspectionResult::Sellable ? 'Available Inventory' : 'Damaged Inventory' }}@if ($inspection->damagedStockEvent) · {{ $inspection->damagedStockEvent->reference }}@endif</p>
                        </div>
                    @empty
                        <p>{{ $item->quarantine ? 'Wrong / Different Item is held for verification. No original-product stock was added.' : 'No QC inspection has been recorded yet.' }}</p>
                    @endforelse
                    <p><span class="font-medium">Related claim or case:</span> {{ $claimReason }}</p>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500">No returned products have been recorded.</p>
        @endforelse
    </div>
@endif
