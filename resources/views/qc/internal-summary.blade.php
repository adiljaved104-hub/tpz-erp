@php($inspection = $getRecord())
@php($checks = $inspection->checks()->get()->where('applicable', true))
<x-filament::section heading="Device Summary">
<h2>{{ $inspection->device->reference }} · Version {{ $inspection->version }} · {{ str($inspection->status->value)->headline() }}</h2>
<p>{{ $inspection->product_snapshot['title'] }} · {{ $inspection->product_snapshot['sku'] }}</p><p>Serial / IMEI: {{ $inspection->device->serial }} · {{ $inspection->warehouse->name }}</p>
<p>{{ $checks->whereNotNull('result')->count() }} / {{ $checks->count() }} applicable checks completed</p>
@if($inspection->requested_configuration)<x-filament::section heading="CUSTOMER REQUIRED CONFIGURATION">@foreach($inspection->requested_configuration as $key => $value)@if(!is_array($value))<p>{{ str($key)->headline() }}: {{ $value }}</p>@endif @endforeach</x-filament::section>@endif
<x-filament::section heading="Original / Final Configuration">@foreach(['original_configuration' => 'Original', 'final_configuration' => 'Final Tested'] as $field => $label)<h3>{{ $label }}</h3>@foreach($inspection->$field ?? [] as $key => $value)@if(filled($value) && !(app(\App\Services\Qc\QcTemplateResolver::class)->forInspection($inspection)['device_type'] === 'tablet' && in_array($key, ['ram', 'ram_mb'], true)))<p>{{ str($key)->headline() }}: {{ $value }}</p>@endif @endforeach @endforeach</x-filament::section>
<p>Grade: {{ $inspection->grade ?? 'Not selected' }}</p><p>{{ $inspection->internal_remarks }}</p>
@include('qc.evidence-status')
</x-filament::section>
