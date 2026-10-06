@php($inspection = $getRecord())
<x-filament::section heading="Device Summary">
<h2>{{ $inspection->device->reference }} · v{{ $inspection->version }} · {{ $inspection->device->serial }}</h2>
<p>{{ $inspection->product_snapshot['title'] }} · {{ $inspection->product_snapshot['sku'] }} · {{ $inspection->warehouse->name }}</p>
<p>Original: {{ $inspection->original_configuration['cpu'] }} · {{ $inspection->original_configuration['ram'] ?? 'Unknown RAM' }} · {{ $inspection->original_configuration['storage'] ?? 'Unknown storage' }}</p>
@if($inspection->requested_configuration)<x-filament::section heading="CUSTOMER REQUIRED CONFIGURATION">
<strong>{{ $inspection->requested_configuration['display_name'] ?? $inspection->requested_configuration['special_requirement'] ?? 'Upgrade required' }}</strong>
@isset($inspection->requested_configuration['target_ram_mb'])<p>RAM: {{ ($inspection->original_configuration['ram_mb'] ?? 0) / 1024 }} GB → {{ $inspection->requested_configuration['target_ram_mb'] / 1024 }} GB</p>@endisset
@isset($inspection->requested_configuration['target_storage_total_gb'])<p>Storage: {{ $inspection->original_configuration['storage_gb'] ?? 'Unknown' }} GB → {{ $inspection->requested_configuration['target_storage_total_gb'] }} GB</p>@endisset
<p>Final tested values must match these targets. Upgrade evidence is required.</p>
</x-filament::section>@endif
</x-filament::section>
