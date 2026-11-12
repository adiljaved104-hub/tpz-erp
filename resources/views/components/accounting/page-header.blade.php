@props(['title', 'description' => null, 'eyebrow' => 'TPZ Accounting'])

<header class="mb-6 flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)<p class="mb-1 text-xs font-semibold uppercase tracking-[0.12em] text-primary-600 dark:text-primary-400">{{ $eyebrow }}</p>@endif
        <h1 class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $title }}</h1>
        @if ($description)<p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>@endif
    </div>
    @if (isset($actions) && $actions->isNotEmpty())
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</header>
