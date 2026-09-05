@props([
    'seconds' => 0,
    'route' => null,
    'wireAction' => null,
    'componentKey' => null,
])

@php($initialSeconds = max(0, (int) $seconds))

<div
    wire:key="{{ $componentKey ? 'otp-resend-'.$componentKey : 'otp-resend-default' }}"
    class="mt-4 text-center"
    x-data="{
        remaining: {{ $initialSeconds }},
        timer: null,
        submitting: false,
        start() {
            clearInterval(this.timer);
            if (this.remaining <= 0) return;
            this.timer = setInterval(() => {
                this.remaining = Math.max(0, this.remaining - 1);
                if (this.remaining === 0) clearInterval(this.timer);
            }, 1000);
        },
    }"
    x-init="start()"
>
    <p
        x-show="remaining > 0"
        x-text="`Resend code in ${remaining}s`"
        style="{{ $initialSeconds === 0 ? 'display:none' : '' }}"
        class="text-sm text-gray-500 dark:text-gray-400"
    >Resend code in {{ $initialSeconds }}s</p>

    @if($route)
        <form
            method="POST"
            action="{{ $route }}"
            x-show="remaining === 0"
            style="{{ $initialSeconds > 0 ? 'display:none' : '' }}"
            x-on:submit="if (submitting) { $event.preventDefault(); return; } submitting = true"
        >
            @csrf
            <x-filament::button
                type="submit"
                color="gray"
                size="sm"
                outlined
                x-bind:disabled="submitting"
            >Resend Code</x-filament::button>
        </form>
    @elseif($wireAction)
        <x-filament::button
            type="button"
            color="gray"
            size="sm"
            outlined
            x-show="remaining === 0"
            style="{{ $initialSeconds > 0 ? 'display:none' : '' }}"
            wire:click="{{ $wireAction }}"
            wire:loading.attr="disabled"
            wire:target="{{ $wireAction }}"
        >Resend Code</x-filament::button>
    @endif
</div>
