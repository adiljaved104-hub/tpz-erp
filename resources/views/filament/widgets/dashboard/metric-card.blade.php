<a
    href="{{ $card['url'] }}"
    data-dashboard-card="{{ $card['key'] }}"
    class="group block h-full min-w-0 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition hover:border-primary-400 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500"
    aria-label="Open {{ $card['label'] }}"
>
    <div class="flex h-full min-h-32 min-w-0 flex-col justify-between gap-3">
        <div class="flex min-w-0 items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                <p class="mt-1 break-words text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ is_int($card['value']) ? number_format($card['value']) : $card['value'] }}
                </p>
            </div>
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-50 text-gray-500 transition group-hover:text-primary-600 dark:bg-white/5 dark:text-gray-400 dark:group-hover:text-primary-400">
                <x-filament::icon :icon="$icons[$card['key']] ?? 'heroicon-o-chart-bar'" class="size-5" />
            </span>
        </div>

        <div class="min-w-0">
            <p class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400" title="{{ $card['description'] }}">{{ $card['description'] }}</p>
            <div class="mt-2 flex min-w-0 flex-wrap items-center justify-between gap-2">
                <span class="text-xs font-semibold text-primary-600 dark:text-primary-400">Open →</span>
                @if (in_array($card['color'], ['warning', 'danger', 'success'], true))
                    <x-filament::badge :color="$card['color']">{{ $card['color'] === 'danger' ? 'Action' : ($card['color'] === 'warning' ? 'Monitor' : 'Current') }}</x-filament::badge>
                @endif
            </div>
        </div>
    </div>
</a>
