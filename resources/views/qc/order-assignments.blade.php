@php
    $items = app(\App\Services\Qc\QcOrderAssignmentService::class)->history($getRecord(), auth()->user());
    $authorization = app(\App\Services\Authorization\QcAuthorization::class);
@endphp
<div class="space-y-5">
    @if($getRecord()->status === \App\Enums\OrderStatus::Draft)
        <p>QC jobs may be prepared in Draft. Final device assignments are available after this Order leaves Draft.</p>
    @endif
    @foreach($items as $item)
        @php($active = $item->qcAssignments->whereNull('released_at'))
        <div>
            <h3 class="font-semibold">{{ $item->sku }} · {{ $item->product_name }}</h3>
            <p class="mb-3">QC Devices: {{ $active->count() }} / {{ $item->ordered_quantity }} Assigned @if($active->count() === $item->ordered_quantity && $active->every(fn ($a) => $a->certificate->isCurrent())) ✓ @endif</p>
            <div class="grid gap-3 md:grid-cols-2">
                @foreach($active as $assignment)
                    @php($certificate = $assignment->certificate)
                    @php($inspection = $certificate->inspection)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <strong class="break-all">{{ $assignment->device->serial }}</strong>
                        <p>{{ $assignment->device->reference }} · v{{ $assignment->certificate_version }}</p>
                        <p>@include('qc.final-specs', ['final' => $certificate->snapshot['final']])</p>
                        <x-filament::badge :color="$certificate->isCurrent() ? 'success' : 'warning'">{{ $certificate->isCurrent() ? 'Current / Verified' : 'QC pending / superseded — not fulfilment-ready' }}</x-filament::badge>
                        <p class="mt-2 text-sm">Certified {{ $certificate->certified_at->format('d M Y H:i T') }} · Assigned by {{ $assignment->assignedBy->name }}</p>
                        <div class="mt-3 flex flex-wrap gap-3 text-sm">
                            @if($authorization->allows(auth()->user(), \App\Enums\QcPermission::View, $inspection))
                                <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\QcInspections\QcInspectionResource::getUrl('view', ['record' => $inspection]) }}">View QC</a>
                            @endif
                            <a class="text-primary-600 underline" href="{{ app(\App\Services\Qc\QcDocumentService::class)->url($certificate) }}" target="_blank" rel="noopener">View Device Passport</a>
                            @if($authorization->allows(auth()->user(), \App\Enums\QcPermission::PrintCertificate, $inspection))
                                <a class="text-primary-600 underline" href="{{ route('qc.certificate', $inspection) }}">Download QC Certificate</a>
                            @endif
                            @if($certificate->isCurrent() && $authorization->allows(auth()->user(), \App\Enums\QcPermission::PrintLabel, $inspection))
                                <a class="text-primary-600 underline" href="{{ route('qc.labels', ['ids' => [$inspection->id]]) }}" target="_blank" rel="noopener">Print QC Label</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @if($item->qcAssignments->whereNotNull('released_at')->isNotEmpty())
                <details class="mt-3"><summary class="cursor-pointer">Released assignment history</summary>
                    @foreach($item->qcAssignments->whereNotNull('released_at') as $assignment)
                        <p class="mt-2 text-sm">{{ $assignment->device->serial }} · {{ $assignment->device->reference }} · v{{ $assignment->certificate_version }} — Released {{ $assignment->released_at->format('d M Y H:i T') }} by {{ $assignment->releasedBy->name }}: {{ $assignment->release_reason }}</p>
                    @endforeach
                </details>
            @endif
        </div>
    @endforeach
</div>
