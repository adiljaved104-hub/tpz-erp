@assets
<script src="{{ asset('js/qc-device-workflow.js') }}"></script>
@endassets
<div x-data="tpzQcScanner($wire)" x-on:close-modal.window="stop()" x-on:pagehide.window="stop()" x-on:visibilitychange.document="if(document.hidden) stop()" x-on:livewire:navigating.document="stop()">
    <p>Scan a Code 128, Code 39, QR Code or Data Matrix device label where supported. Review/edit the detected value in the normal Serial / IMEI field before starting QC.</p>
    <p>Scanning fills the identifier only; it does not replace the required Serial / IMEI evidence photo.</p>
    <video x-ref="video" class="tpz-qc-scanner-video" playsinline muted x-show="running" x-cloak></video>
    <div class="tpz-qc-upload-controls">
        <x-filament::button type="button" icon="heroicon-o-camera" x-on:click="start()" x-bind:disabled="starting || running">Scan Serial / IMEI</x-filament::button>
        <x-filament::button type="button" color="gray" x-on:click="stop()">Stop / Cancel Camera</x-filament::button>
    </div>
    <p x-text="message" role="status" aria-live="polite"></p>
    <p x-show="detected" x-cloak>Detected: <strong x-text="detected"></strong> — review the manual Serial / IMEI field.</p>
</div>
