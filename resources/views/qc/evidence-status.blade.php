@php($inspection = $getRecord())
@php($proofs = $inspection->evidence()->where('customer_visible', true)->pluck('kind')->all())
<x-filament::section heading="Critical Evidence">
<p>Use Add Critical Evidence above. Customer-visible proof must not show passwords, costs or customer personal data.</p>
@php($upgrade = app(\App\Services\Qc\QcInspectionService::class)->hasUpgrade($inspection, $getLivewire()->data['final_configuration'] ?? $inspection->final_configuration))
@foreach(['serial','physical','display','system', ...($upgrade ? ['upgrade'] : [])] as $kind)
<p>{{ \App\Services\Qc\QcEvidenceService::KINDS[$kind] }}: {{ in_array($kind, $proofs, true) ? 'Uploaded' : 'Required - not uploaded' }}</p>
@error('data.evidence.'.$kind)<p role="alert">{{ $message }}</p>@enderror
@endforeach
</x-filament::section>
