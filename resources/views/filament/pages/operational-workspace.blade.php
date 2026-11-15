<x-filament-panels::page>
    <div class="tpz-workspace tpz-unified-workspace tpz-reference-workspace">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-center justify-between gap-4">
            <div class="tpz-native-workspace-heading">
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb">
                    <span>{{ str_replace(' Workspace', '', $workspace['title']) }}</span>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">{{ $workspace['title'] }}</span>
                </nav>
                <div class="tpz-native-workspace-heading-main">
                    <span class="tpz-unified-module-icon"><x-filament::icon :icon="$workspace['icon']" class="size-6" /></span>
                    <div>
                        <h1 class="tpz-workspace-title">{{ $workspace['title'] }}</h1>
                        <p class="tpz-workspace-subtitle">{{ $workspace['description'] }}</p>
                    </div>
                </div>
            </div>
            @if($activeModule !== 'overview' && $selectedModuleData)
                <x-filament::button tag="a" :href="$selectedModuleData['url']" icon="heroicon-o-arrow-up-right">
                    Open {{ $selectedModuleData['label'] }}
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
                wire:click="selectModule('overview')">
                <x-filament::icon icon="heroicon-o-squares-2x2" class="tpz-native-tab-icon size-4" />
                Overview
            </button>
            @foreach($moduleTabs as $index => $tab)
                <button type="button" wire:key="workspace-tab-{{ $index }}"
                    wire:click="selectModule('{{ $index }}')"
                    aria-selected="{{ $activeModule === (string) $index ? 'true' : 'false' }}"
                    class="tpz-workspace-tab {{ $activeModule === (string) $index ? 'is-active' : '' }}">
                    <x-filament::icon :icon="$tab['icon']" class="tpz-native-tab-icon size-4" />
                    <span class="tpz-native-tab-label">{{ $tab['label'] }}</span>
                    @php
                        $matchingMetric = $metrics->firstWhere('label', $tab['label']);
                    @endphp
                    @if($matchingMetric)
                        <span class="tpz-native-tab-count">{{ number_format($matchingMetric['value']) }}</span>
                    @endif
                </button>
            @endforeach
        </nav>

        @if($activeModule === 'overview')
            @if($metrics->isNotEmpty())
                <section class="tpz-unified-overview-panel" aria-label="Operational overview">
                    <div class="tpz-unified-section-title">
                        <h2>Operational Overview</h2>
                        <span>Live, permission-scoped totals</span>
                    </div>
                    <div class="tpz-native-overview-metrics tpz-native-overview-metrics--primary">
                        @foreach($metrics as $metric)
                            <a href="{{ $metric['url'] }}"
                                class="tpz-native-overview-metric tpz-native-overview-metric--{{ ['blue','green','amber','violet','rose'][$loop->index % 5] }}"
                                wire:key="metric-{{ $loop->index }}">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="$metric['icon']" class="size-5" /></span>
                                <span class="tpz-native-overview-label">{{ $metric['label'] }}</span>
                                <strong>{{ number_format($metric['value']) }}</strong>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="tpz-native-overview-columns">
                @if($quickActions->isNotEmpty())
                    <section class="tpz-native-list-panel" aria-label="Quick actions">
                        <div class="tpz-native-panel-title">
                            <span class="tpz-native-overview-icon"><x-filament::icon icon="heroicon-o-bolt" class="size-5" /></span>
                            <h2>Quick Actions</h2>
                        </div>
                        <div class="tpz-native-quick-actions">
                            @foreach($quickActions as $action)
                                <a href="{{ $action['url'] }}">
                                    <x-filament::icon :icon="$action['icon']" class="size-4" />
                                    <span>{{ $action['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if($recentRecords->isNotEmpty())
                    <section class="tpz-native-list-panel" aria-label="Recent records">
                        <div class="tpz-native-panel-title">
                            <span class="tpz-native-overview-icon"><x-filament::icon icon="heroicon-o-clock" class="size-5" /></span>
                            <h2>Recent Activity</h2>
                        </div>
                        <div class="tpz-native-recent-list">
                            @foreach($recentRecords as $record)
                                <a href="{{ $record['url'] }}" wire:key="recent-{{ $loop->index }}">
                                    <span class="tpz-native-overview-icon"><x-filament::icon icon="heroicon-o-document-text" class="size-4" /></span>
                                    <strong>{{ $record['reference'] }}</strong>
                                    <small>{{ $record['date'] }}</small>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            @if($moduleTabs->isNotEmpty())
                <section class="tpz-native-list-panel" aria-label="Authorized modules">
                    <div class="tpz-native-panel-title">
                        <span class="tpz-native-overview-icon"><x-filament::icon icon="heroicon-o-square-3-stack-3d" class="size-5" /></span>
                        <h2>Available Modules</h2>
                    </div>
                    <div class="tpz-native-module-list">
                        @foreach($moduleTabs as $index => $tab)
                            <button type="button" wire:key="module-jump-{{ $index }}" wire:click="selectModule('{{ $index }}')">
                                <x-filament::icon :icon="$tab['icon']" class="size-5" />
                                <span>{{ $tab['label'] }}</span>
                                <x-filament::icon icon="heroicon-o-chevron-right" class="size-4" />
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif
        @elseif($selectedModuleData)
            <section class="tpz-unified-overview-panel" aria-label="{{ $selectedModuleData['label'] }} details">
                <div class="tpz-native-module-header">
                    <span class="tpz-unified-module-icon"><x-filament::icon :icon="$selectedModuleData['icon']" class="size-6" /></span>
                    <div>
                        <h2>{{ $selectedModuleData['label'] }}</h2>
                        <p>Existing authorized records and actions for this module.</p>
                    </div>
                    @if($selectedModuleData['total'] !== null)
                        <div class="tpz-native-module-count">
                            <small>Total Records</small>
                            <strong>{{ number_format($selectedModuleData['total']) }}</strong>
                        </div>
                    @endif
                </div>
                @if($selectedModuleData['total'] !== null)
                    <div class="tpz-native-overview-metrics tpz-native-module-totals">
                        <div class="tpz-native-overview-metric tpz-native-overview-metric--blue">
                            <span class="tpz-native-overview-icon"><x-filament::icon :icon="$selectedModuleData['icon']" class="size-5" /></span>
                            <span class="tpz-native-overview-label">{{ $selectedModuleData['label'] }} Records</span>
                            <strong>{{ number_format($selectedModuleData['total']) }}</strong>
                        </div>
                    </div>
                @endif
                @if($selectedModuleData['records']->isNotEmpty())
                    <div class="tpz-native-record-table-wrap">
                        <table class="tpz-native-record-table">
                            <thead><tr><th>Reference</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                                @foreach($selectedModuleData['records'] as $record)
                                    <tr wire:key="selected-record-{{ $loop->index }}">
                                        <td><strong>{{ $record['reference'] }}</strong></td>
                                        <td>{{ $record['date'] }}</td>
                                        <td>{{ $record['status'] ?? '—' }}</td>
                                        <td><a href="{{ $record['url'] }}">View →</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="tpz-native-no-preview">Open the existing module to access its authorized records, filters, and actions.</p>
                @endif
                <div class="tpz-native-module-footer">
                    <x-filament::button tag="a" :href="$selectedModuleData['url']" icon="heroicon-o-arrow-up-right">
                        Open full {{ $selectedModuleData['label'] }} module
                    </x-filament::button>
                </div>
            </section>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">All records, approvals, financial controls, and inventory responsibility restrictions continue through the existing ERP workflows.</p>
    </div>
</x-filament-panels::page>
