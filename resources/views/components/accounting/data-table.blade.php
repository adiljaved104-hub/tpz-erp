@props(['minimumWidth' => '48rem'])

<div {{ $attributes->class(['overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900']) }}>
    <table class="w-full text-sm" style="min-width: {{ $minimumWidth }}">
        {{ $slot }}
    </table>
</div>
