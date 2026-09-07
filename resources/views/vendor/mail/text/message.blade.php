@php($applicationBrand = app(\App\Services\Branding\ApplicationBranding::class))
<x-mail::layout>
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ $applicationBrand->fullName() }}
        </x-mail::header>
    </x-slot:header>

    {{ $slot }}

    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    <x-slot:footer>
        <x-mail::footer>
            {{ $applicationBrand->copyright() }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
