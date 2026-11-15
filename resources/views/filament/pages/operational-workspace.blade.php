<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-start justify-between gap-4">
            <div>
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb">
                    <span>{{ str_replace(' Workspace', '', $workspace['title']) }}</span>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">{{ $workspace['title'] }}</span>
                </nav>
                <h1 class="tpz-workspace-title">{{ $workspace['title'] }}</h1>
                <p class="tpz-workspace-subtitle">{{ $workspace['description'] }}</p>
            </div>
            @if($activeModule !== 'overview' && isset($moduleTabs[(int) $activeModule]))
                <x-filament::button tag="a" :href="$moduleTabs[(int) $activeModule]['url']" icon="heroicon-o-arrow-up-right">
                    Open {{ $moduleTabs[(int) $activeModule]['label'] }}
                </x-filament::button>
            @elseif($quickActions->isNotEmpty())
                <x-filament::button tag="a" :href="$quickActions->first()['url']" :icon="$quickActions->first()['icon']">
                    {{ $quickActions->first()['label'] }}
                </x-filament::button>
            @endif
        </header>

        <nav class="tpz-workspace-tabs" aria-label="{{ $workspace['title'] }} sections">
            <button type="button" class="tpz-workspace-tab {{ $activeModule === 'overview' ? 'is-active' : '' }}"
                aria-selected="{{ $activeModule === 'overview' ? 'true' : 'false' }}"
                wire:click="selectModule('overview')">Overview</button>
            @foreach($moduleTabs as $index => $tab)
                <button type="button" wire:key="workspace-tab-{{ $index }}"
                    wire:click="selectModule('{{ $index }}')"
                    aria-selected="{{ $activeModule === (string) $index ? 'true' : 'false' }}"
                    class="tpz-workspace-tab {{ $activeModule === (string) $index ? 'is-active' : '' }}">
                    {{ $tab['label'] }}
                </button>
            @endforeach
        </nav>

        @if($activeModule === 'overview')
            @if($metrics->isNotEmpty())
                <section aria-label="Operational overview" class="tpz-unified-section">
                    <div class="tpz-unified-section-title">
                        <h2>Operational overview</h2>
                        <span>Live, permission-scoped totals</span>
                    </div>
                    <div class="tpz-workspace-metrics">
                        @foreach($metrics as $metric)
                            <a href="{{ $metric['url'] }}" class="tpz-workspace-metric tpz-workspace-metric--blue" wire:key="metric-{{ $loop->index }}">
                                <span class="tpz-workspace-metric-icon"><x-filament::icon :icon="$metric['icon']" class="size-5" /></span>
                                <div class="min-w-0">
                                    <p class="tpz-workspace-metric-label">{{ $metric['label'] }}</p>
                                    <p class="tpz-workspace-metric-value">{{ number_format($metric['value']) }}</p>
                                    <p class="tpz-workspace-metric-link">View records →</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($quickActions->isNotEmpty())
                <section aria-label="Quick actions" class="tpz-unified-section">
                    <div class="tpz-unified-section-title"><h2>Quick actions</h2><span>Existing authorized ERP workflows</span></div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($quickActions as $action)
                            <x-filament::button tag="a" size="sm" :href="$action['url']" :icon="$action['icon']">{{ $action['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($recentRecords->isNotEmpty())
                <x-filament::section heading="Recent authorized records">
                    <div class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($recentRecords as $record)
                            <a href="{{ $record['url'] }}" wire:key="recent-{{ $loop->index }}"
                                class="flex flex-wrap items-center justify-between gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $record['reference'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $record['date'] }} · Open record →</span>
                            </a>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @else
            @php($chosenTab = $moduleTabs[(int) $activeModule] ?? null)
            @if($chosenTab)
                <x-filament::section>
                    <div class="tpz-unified-module-preview">
                        <span class="tpz-unified-module-icon"><x-filament::icon :icon="$chosenTab['icon']" class="size-6" /></span>
                        <div>
                            <h2>{{ $chosenTab['label'] }}</h2>
                            <p>Continue working with your existing authorized {{ $chosenTab['label'] }} records, filters and actions.</p>
                        </div>
                        <x-filament::button tag="a" :href="$chosenTab['url']" icon="heroicon-o-arrow-up-right">
                            Open full module
                        </x-filament::button>
                    </div>
                </x-filament::section>
            @endif
        @endif
        <p class="text-xs text-gray-500 dark:text-gray-400">All records and changes continue through the existing ERP workflows and Access Control.</p>
    </div>
</x-filament-panels::page>
