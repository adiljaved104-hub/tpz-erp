@php
    $holders = $getState() ?? [];
    $visibleHolderCount = 3;
    $additionalHolderCount = max(0, count($holders) - $visibleHolderCount);
@endphp

@if (count($holders))
    <div x-data="{ expanded: false }" class="flex flex-wrap items-center gap-1">
        @foreach ($holders as $index => $holder)
            <span @if ($index >= $visibleHolderCount) x-show="expanded" x-cloak @endif>
                <x-filament::badge size="sm" color="gray" class="max-w-48" :title="$holder['label'].' · '.number_format((int) $holder['allocated'] )">
                    <span class="inline-flex min-w-0 max-w-full items-center gap-1">
                        <span class="min-w-0 truncate">{{ $holder['label'] }}</span>
                        <span class="shrink-0">· {{ number_format((int) $holder['allocated']) }}</span>
                    </span>
                </x-filament::badge>
            </span>
        @endforeach

        @if ($additionalHolderCount > 0)
            <button
                type="button"
                class="fi-link fi-size-xs whitespace-nowrap"
                x-bind:aria-expanded="expanded.toString()"
                x-on:click.stop="expanded = ! expanded"
                x-text="expanded ? 'Show less' : '+{{ $additionalHolderCount }} more'"
            >+{{ $additionalHolderCount }} more</button>
        @endif
    </div>
@else
    <span class="text-sm text-gray-500 dark:text-gray-400">No recorded allocation</span>
@endif
