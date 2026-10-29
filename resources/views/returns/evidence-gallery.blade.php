@php
    $source = $getRecord();
    $return = $source instanceof \App\Models\CustomerReturn
        ? $source
        : ($source instanceof \App\Models\SafetClaim
            ? $source->customerReturn
            : ($source instanceof \App\Models\WarrantyRepair ? $source->damagedStockEvent?->customerReturn : null));
    $allowed = $return && auth()->user() && app(\App\Services\Returns\CustomerReturnEvidenceAuthorization::class)->canView(auth()->user(), $return);
    $allItems = $allowed ? $return->items()->with(['findings.evidence.label', 'findings.evidence.uploadedBy'])->get() : collect();
    $items = $source instanceof \App\Models\SafetClaim
        ? $allItems->where('id', $source->customer_return_item_id) : $allItems;
    $videoParts = $allowed ? $allItems->flatMap(fn ($item) => $item->findings->flatMap(fn ($finding) => $finding->evidence))
        ->where('media_type', 'video')->sortBy('id')->values()
        ->mapWithKeys(fn ($proof, $index) => [$proof->id => $index + 1]) : collect();
    $evidenceCount = $items->sum(fn ($item) => $item->findings->sum(fn ($finding) => $finding->evidence->count()));
@endphp

@if ($allowed)
    <div class="tpz-return-gallery">
        @if ($evidenceCount === 0)
            <p class="tpz-return-gallery-empty">No return evidence has been saved yet.</p>
        @endif
        @foreach ($items as $item)
            @php
                $itemEvidence = $item->findings->flatMap(fn ($finding) => $finding->evidence
                    ->map(fn ($proof) => ['finding' => $finding, 'proof' => $proof]));
            @endphp
                @continue ($itemEvidence->isEmpty())
                <section class="tpz-return-gallery-group" aria-label="Evidence for {{ $item->sku_snapshot }}">
                    <div class="tpz-return-gallery-heading">
                        <div>
                            <h3>{{ $item->sku_snapshot }} · {{ $item->product_name_snapshot }}</h3>
                            <p>Return reason: {{ str($item->return_reason->value)->replace('_', ' ')->headline() }}</p>
                        </div>
                        <span class="tpz-return-gallery-count">{{ $itemEvidence->count() }} {{ \Illuminate\Support\Str::plural('file', $itemEvidence->count()) }}</span>
                    </div>
                    <div class="tpz-return-gallery-grid">
                        @foreach ($itemEvidence as $entry)
                            @php
                                $finding = $entry['finding'];
                                $proof = $entry['proof'];
                                $label = $proof->readableLabel();
                                $previewUrl = route('customer-return-evidence.preview', $proof);
                                $kind = $proof->media_type === 'video' ? 'Video — Part '.($videoParts[$proof->id] ?? '?') : 'Photo';
                                $deviceTime = $proof->deviceCaptureTime();
                                $size = $proof->byte_size >= 1048576
                                    ? number_format($proof->byte_size / 1048576, 1).' MiB'
                                    : number_format($proof->byte_size / 1024, 1).' KiB';
                            @endphp
                            <article class="tpz-return-gallery-card">
                                <div class="tpz-return-gallery-card-heading">
                                    <strong>{{ $kind }}</strong>
                                    <span class="tpz-return-gallery-status {{ $label !== null ? 'is-ready' : 'is-pending' }}">
                                        {{ $label !== null ? 'Labeled copy ready' : 'Labeled copy not ready' }}
                                    </span>
                                </div>
                                <div class="tpz-return-gallery-media" @if ($proof->media_type === 'video') style="display:block;height:auto;min-height:0;max-height:none" @endif>
                                    @if ($proof->media_type === 'photo')
                                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Open larger {{ $kind }} preview">
                                            <img src="{{ $previewUrl }}" alt="{{ $kind }} for return {{ $return->reference }}" loading="lazy">
                                        </a>
                                    @else
                                        <video controls playsinline preload="metadata" style="display:block;width:100%;height:auto;max-height:34rem;object-fit:contain" aria-label="{{ $kind }} for return {{ $return->reference }}">
                                            <source src="{{ $previewUrl }}" type="{{ $label?->mime_type ?? $proof->mime_type }}">
                                        </video>
                                    @endif
                                </div>
                                <div class="tpz-return-gallery-summary">
                                    <span>Device-reported: {{ $deviceTime?->format('d M Y, H:i:s T') ?? 'Capture time not supplied' }}</span>
                                    <span>{{ $proof->capture_timezone ?: 'Time zone not supplied' }} · Uploaded by {{ $proof->uploadedBy?->name ?? 'Unknown' }}</span>
                                    <span>Finding: {{ str($finding->problem_type)->replace('_', ' ')->headline() }}</span>
                                    @if ($label === null)
                                        <span>Original available. Ask an administrator if the labeled copy stays unavailable.</span>
                                    @endif
                                </div>
                                <div class="tpz-return-gallery-actions">
                                    <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer">Open larger preview</a>
                                    <a href="{{ route('customer-return-evidence.original', $proof) }}">Download original</a>
                                    @if ($label !== null)
                                        <a href="{{ route('customer-return-evidence.labeled', $proof) }}">Download labeled copy</a>
                                    @endif
                                </div>
                                <details class="tpz-return-gallery-details">
                                    <summary>Details</summary>
                                    <dl>
                                        <div><dt>Evidence ID</dt><dd>{{ $proof->id }}</dd></div>
                                        <div><dt>Evidence type</dt><dd>{{ $kind }}</dd></div>
                                        @if ($finding->physical_condition)<div><dt>Physical condition</dt><dd>{{ $finding->physical_condition }}</dd></div>@endif
                                        @if ($finding->actual_product_name || $finding->actual_serial_number)
                                            <div><dt>Actually received</dt><dd>{{ $finding->actual_product_name ?: 'Unidentified' }}@if ($finding->actual_serial_number) · Serial {{ $finding->actual_serial_number }}@endif</dd></div>
                                        @endif
                                        @if ($finding->remarks)<div><dt>Finding remarks</dt><dd>{{ $finding->remarks }}</dd></div>@endif
                                        <div><dt>Device-reported capture</dt><dd>{{ $deviceTime?->format('d M Y H:i:s T') ?? 'Not supplied' }}</dd></div>
                                        <div><dt>Reported time zone</dt><dd>{{ $proof->capture_timezone ?? 'Not supplied' }}</dd></div>
                                        @if ($proof->media_type === 'video' && $proof->device_recording_orientation)
                                            <div><dt>Device pose at capture</dt><dd>{{ str($proof->device_recording_orientation)->replace('_', ' ')->headline() }} (device sensor)</dd></div>
                                        @endif
                                        @if ($proof->media_type === 'video' && $proof->video_rotation_degrees !== null)
                                            <div><dt>Employee-confirmed video correction</dt><dd>{{ $proof->video_rotation_degrees }}° clockwise after original display metadata (labeled copy only)</dd></div>
                                        @endif
                                        <div><dt>Server received</dt><dd>{{ $proof->uploaded_at?->utc()->format('d M Y H:i:s').' UTC' }}</dd></div>
                                        <div><dt>File size</dt><dd>{{ $size }}</dd></div>
                                        <div><dt>Original SHA-256</dt><dd><code class="tpz-return-gallery-hash">{{ $proof->sha256 }}</code></dd></div>
                                    </dl>
                                </details>
                            </article>
                        @endforeach
                    </div>
                </section>
        @endforeach
    </div>
@endif
