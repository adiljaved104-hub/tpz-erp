@php
    $inspection = $getRecord();
    $final = $getLivewire()->data['final_configuration'] ?? $inspection->final_configuration;
    $progress = app(\App\Services\Qc\QcEvidenceService::class)->progress($inspection, $final);
    $authorization = app(\App\Services\Authorization\QcAuthorization::class);
    $canUpload = $inspection->status !== \App\Enums\QcInspectionStatus::Completed && $authorization->allows(auth()->user(), \App\Enums\QcPermission::Update, $inspection);
@endphp
@assets
<script src="{{ asset('js/qc-device-workflow.js') }}"></script>
@endassets
<div id="qc-critical-evidence" x-on:qc-evidence-missing.window="$el.scrollIntoView({behavior: 'smooth', block: 'start'}); $el.focus()" tabindex="-1">
<x-filament::section heading="Critical Evidence">
<p>QC: <strong>{{ $inspection->device->reference }}</strong> · Serial / IMEI: <strong>{{ $inspection->device->serial }}</strong></p>
<p role="status" data-testid="qc-evidence-progress"><strong>Critical Evidence: {{ count($progress['complete']) }} / {{ count($progress['required']) }} complete</strong> · {{ count($progress['missing']) }} remaining</p>
<p>Customer-visible photos must not contain passwords, costs or customer personal data. Original images remain private.</p>
<div class="tpz-qc-evidence-grid">
@foreach([...$progress['required'], 'additional'] as $kind)
@php
    $photos = $progress['proofs']->where('kind', $kind);
    $complete = in_array($kind, $progress['complete'], true);
    $additional = $kind === 'additional';
@endphp
<section class="tpz-qc-evidence-card {{ ! $additional && ! $complete ? 'tpz-qc-evidence-missing' : '' }}" wire:key="qc-evidence-{{ $inspection->id }}-{{ $kind }}" data-testid="qc-evidence-{{ $kind }}" x-data="tpzQcUpload($wire, @js($kind))">
    <h3><strong>{{ \App\Services\Qc\QcEvidenceService::KINDS[$kind] }}</strong></h3>
    <p>{{ $additional ? 'Optional · Customer visible or internal only' : ($complete ? '✓ Uploaded' : 'Required / Missing') }} · {{ $photos->count() }} photo(s)</p>
    @error('data.evidence.'.$kind)<p class="tpz-qc-error" role="alert">{{ $message }}</p>@enderror
    @error('evidenceUploads.'.$kind)<p class="tpz-qc-error" role="alert">{{ $message }}</p>@enderror
    <div class="tpz-qc-thumbnails">
    @foreach($photos as $photo)
    @php($canView = $authorization->allows(auth()->user(), $photo->customer_visible ? \App\Enums\QcPermission::ViewCustomerEvidence : \App\Enums\QcPermission::ViewInternalEvidence, $inspection))
    <figure>
        @if($canView)<a href="{{ route('qc.evidence.internal', $photo) }}" target="_blank" rel="noopener"><img loading="lazy" src="{{ route('qc.evidence.internal', $photo) }}" alt="{{ \App\Services\Qc\QcEvidenceService::KINDS[$kind] }} photo"></a>@else<p>Image access restricted</p>@endif
        <figcaption>{{ $photo->customer_visible ? 'Customer visible' : 'Internal only' }}<br>{{ $photo->uploaded_at->format('d M Y H:i:s T') }}</figcaption>
    </figure>
    @endforeach
    </div>
    @if($canUpload)
    @if($additional)<label class="tpz-qc-upload-controls"><input type="checkbox" wire:model="additionalCustomerVisible"> Customer Visible (otherwise Internal Only)</label>@endif
    <div class="tpz-qc-upload-controls">
        <input x-ref="camera" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="tpz-qc-file-input" @change="upload($event.target.files)" aria-label="Take {{ \App\Services\Qc\QcEvidenceService::KINDS[$kind] }} photo">
        <input x-ref="photos" type="file" accept="image/jpeg,image/png,image/webp" multiple class="tpz-qc-file-input" @change="upload($event.target.files)" aria-label="Choose {{ \App\Services\Qc\QcEvidenceService::KINDS[$kind] }} photos">
        <x-filament::button type="button" icon="heroicon-o-camera" x-on:click="$refs.camera.click()" x-bind:disabled="busy">Take Photo</x-filament::button>
        <x-filament::button type="button" color="gray" icon="heroicon-o-photo" x-on:click="$refs.photos.click()" x-bind:disabled="busy">Choose Photos</x-filament::button>
        <x-filament::button type="button" color="gray" x-show="retry" x-cloak x-on:click="save()" x-bind:disabled="busy">Retry Upload</x-filament::button>
    </div>
    <p x-show="busy" x-cloak role="status" x-text="'Uploading / watermarking… ' + percent + '%'" aria-live="polite"></p>
    <p class="tpz-qc-error" x-show="message" x-cloak x-text="message" role="alert"></p>
    <small>JPG/PNG/WEBP · maximum 8 MB each · up to 10 photos per submission.</small>
    @endif
</section>
@endforeach
</div>
</x-filament::section>
</div>
