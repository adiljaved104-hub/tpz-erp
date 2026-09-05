@props([
    'name' => 'code',
    'model' => null,
    'label' => 'Verification Code',
    'value' => '',
    'showLabel' => true,
])

@php
    $initialCode = substr(preg_replace('/\D/', '', (string) old($name, $value)) ?: '', 0, 6);
    $groupId = $name.'-otp-label';
@endphp

<div
    @if($model)
        x-data="{ code: $wire.entangle(@js($model)).live }"
    @else
        x-data="{ code: @js($initialCode) }"
    @endif
>
    <span
        id="{{ $groupId }}"
        @if($showLabel) class="mb-2 block text-sm font-medium" @else class="fi-sr-only" @endif
    >{{ $label }}</span>
    <input
        type="hidden"
        name="{{ $name }}"
        x-model="code"
    />
    <x-filament::input.one-time-code
        x-model="code"
        :length="6"
        aria-labelledby="{{ $groupId }}"
    >
        <x-slot name="input" aria-label="Digit 1 of 6" pattern="[0-9]"></x-slot>
    </x-filament::input.one-time-code>
    @error($name)<p class="mt-2 text-sm text-danger-600">{{ $message }}</p>@enderror
</div>
