@php($inspection = $getRecord())
@php($checks = $inspection->checks()->get()->where('applicable', true))
<x-filament::section heading="Device Summary">
<h2>{{ $inspection->device->reference }} · Version {{ $inspection->version }} · {{ str($inspection->status->value)->headline() }}</h2>
<p>{{ $inspection->product_snapshot['title'] }} · {{ $inspection->product_snapshot['sku'] }}</p><p>Serial / IMEI: {{ $inspection->device->serial }} · {{ $inspection->warehouse->name }}</p>
<p>{{ $checks->whereNotNull('result')->count() }} / {{ $checks->count() }} applicable checks completed</p>
@if($inspection->requested_configuration)<x-filament::section heading="CUSTOMER REQUIRED CONFIGURATION">@foreach($inspection->requested_configuration as $key => $value)@if(!is_array($value))<p>{{ str($key)->headline() }}: {{ $value }}</p>@endif @endforeach</x-filament::section>@endif
<x-filament::section heading="Original / Final Configuration">@foreach(['original_configuration' => 'Original', 'final_configuration' => 'Final Tested'] as $field => $label)<h3>{{ $label }}</h3>@foreach($inspection->$field ?? [] as $key => $value)<p>{{ str($key)->headline() }}: {{ $value ?? 'Not entered' }}</p>@endforeach @endforeach</x-filament::section>
<p>Grade: {{ $inspection->grade ?? 'Not selected' }}</p><p>{{ $inspection->internal_remarks }}</p>
<x-filament::section heading="Critical Evidence"><p>Required: Serial, Physical, Display, System{{ $inspection->requested_configuration ? ', Upgrade' : '' }}. Originals remain private.</p>
@foreach($inspection->evidence as $image)@if(app(\App\Services\Authorization\QcAuthorization::class)->allows(auth()->user(), $image->customer_visible ? \App\Enums\QcPermission::ViewCustomerEvidence : \App\Enums\QcPermission::ViewInternalEvidence, $inspection))<p><a href="{{ route('qc.evidence.internal', $image) }}" target="_blank">{{ \App\Services\Qc\QcEvidenceService::KINDS[$image->kind] }} · {{ $image->customer_visible ? 'Customer visible' : 'Internal only' }} · {{ $image->uploaded_at->format('d M Y H:i:s') }}</a></p>@endif @endforeach
</x-filament::section></x-filament::section>
