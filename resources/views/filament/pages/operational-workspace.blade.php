<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="tpz-workspace-eyebrow">{{ $workspace['eyebrow'] }}</p>
                <h1 class="tpz-workspace-title">{{ $workspace['title'] }}</h1>
                <p class="tpz-workspace-subtitle">{{ $workspace['description'] }}</p>
            </div>
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl border border-white/20 bg-white/10">
                <x-filament::icon :icon="$workspace['icon']" class="size-6 text-white" />
            </span>
        </header>

        @if($metrics->isNotEmpty())
            <section aria-label="Live operational snapshot">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Operational overview</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Live, permission-scoped totals</span>
                </div>
                <div class="tpz-workspace-metrics">
                    @foreach($metrics as $metric)
                        <a href="{{ $metric['url'] }}" class="tpz-workspace-metric tpz-workspace-metric--blue" wire:key="metric-{{ $loop->index }}">
                            <span class="tpz-workspace-metric-icon"><x-filament::icon :icon="$metric['icon']" class="size-5" /></span>
                            <div class="min-w-0">
                                <p class="tpz-workspace-metric-label">{{ $metric['label'] }}</p>
                                <p class="tpz-workspace-metric-value">{{ number_format($metric['value']) }}</p>
                                <p class="tpz-workspace-metric-link">Open records →</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if($quickActions->isNotEmpty())
            <section aria-label="Authorized quick actions">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Quick actions</h2>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Existing ERP workflows</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach($quickActions as $action)
                        <x-filament::button tag="a" size="sm" :href="$action['url']" :icon="$action['icon']">{{ $action['label'] }}</x-filament::button>
                    @endforeach
                </div>
            </section>
        @endif

        <nav class="tpz-workspace-tabs" aria-label="{{ $workspace['title'] }} sections">
            @foreach($sections as $index => $section)
                <a href="#workspace-section-{{ $index }}" class="tpz-workspace-tab {{ $loop->first ? 'is-active' : '' }}">{{ $section['title'] }}</a>
            @endforeach
        </nav>

        @foreach($sections as $index => $section)
            <x-filament::section>
                <div id="workspace-section-{{ $index }}" class="mb-4 scroll-mt-24">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $section['title'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $section['description'] }}</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach($section['links'] as $link)
                        <a wire:key="workspace-{{ $index }}-{{ $loop->index }}" href="{{ $link['url'] }}" class="group flex min-w-0 items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-primary-400 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-white/10 dark:bg-gray-900">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                <x-filament::icon :icon="$link['icon']" class="size-5" />
                            </span>
                            <span class="min-w-0 flex-1 text-sm font-semibold text-gray-900 group-hover:text-primary-700 dark:text-white dark:group-hover:text-primary-300">{{ $link['label'] }}</span>
                            <x-filament::icon icon="heroicon-o-arrow-up-right" class="size-4 shrink-0 text-gray-400" />
                        </a>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
        <p class="text-xs text-gray-500 dark:text-gray-400">All actions open the existing ERP modules. Permissions, approval requirements, stock responsibility and accounting posting controls remain unchanged.</p>
    </div>
</x-filament-panels::page>
