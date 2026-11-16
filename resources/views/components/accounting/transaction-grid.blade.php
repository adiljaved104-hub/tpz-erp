@props(['label' => 'Transaction lines', 'minimumWidth' => '62rem'])

<div role="region" aria-label="{{ $label }}" tabindex="0" {{ $attributes->class(['overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10']) }}>
    <table class="w-full text-sm" style="min-width: {{ $minimumWidth }}">
        {{ $slot }}
    </table>
</div>
