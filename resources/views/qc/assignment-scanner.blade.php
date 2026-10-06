@assets
<script src="{{ asset('js/qc-device-workflow.js') }}"></script>
@endassets
<div x-data="tpzQcScanner($wire, {accept: value => $wire.resolveQcAssignmentScan(value)})" x-on:close-modal.window="stop()" x-on:pagehide.window="stop()" x-on:visibilitychange.document="if(document.hidden) stop()" x-on:livewire:navigating.document="stop()">
    <p>Select an Order Item first. Scan its TPZ QC verification QR or a supported serial barcode; manual Serial / QC ID search remains available.</p>
    <video x-ref="video" class="tpz-qc-scanner-video" playsinline muted x-show="running" x-cloak></video>
    <div class="tpz-qc-upload-controls">
        <x-filament::button type="button" icon="heroicon-o-camera" x-on:click="start()" x-bind:disabled="starting || running">Scan QC / Serial</x-filament::button>
        <x-filament::button type="button" color="gray" x-on:click="stop()">Stop / Cancel Camera</x-filament::button>
    </div>
    <p x-text="message" role="status" aria-live="polite"></p>
</div>
