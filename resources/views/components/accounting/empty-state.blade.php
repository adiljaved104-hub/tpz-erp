@props(['title', 'description' => null, 'icon' => 'heroicon-o-rectangle-stack'])

<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/15">
    <x-filament::icon :icon="$icon" class="mb-3 h-8 w-8 text-gray-400" />
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
    @if ($description)<p class="mt-1 max-w-xl text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>@endif
</div>
