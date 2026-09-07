@php($applicationBrand = app(\App\Services\Branding\ApplicationBranding::class))
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
@if ($applicationBrand->emailLogoUrl())
<img src="{{ $applicationBrand->emailLogoUrl() }}" class="logo" alt="{{ config('branding.email.logo_alt', $applicationBrand->fullName()) }}">
@else
{{ $applicationBrand->fullName() }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ $applicationBrand->copyright() }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
