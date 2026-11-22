<x-filament-panels::page>
    @once
        <style>
            .tpz-return-workspace-modal .fi-modal-header { padding-block: .8rem; }
            .tpz-return-workspace-modal .fi-modal-content { padding-block: .7rem; }
            .tpz-return-workspace-modal .fi-sc-section {
                border: 1px solid rgba(16, 91, 166, .16);
                border-radius: .8rem;
                overflow: hidden;
            }
            .tpz-return-workspace-modal .fi-sc-section-header {
                padding: .8rem 1rem;
                border-bottom: 1px solid rgba(16, 91, 166, .1);
                background: linear-gradient(110deg, rgba(13, 110, 205, .095), rgba(58, 144, 245, .025));
            }
            .tpz-return-workspace-modal .fi-sc-section-header-heading { color: #10395b; font-size: .95rem; font-weight: 700; }
            .tpz-return-workspace-modal .fi-sc-section-content { padding: .8rem 1rem; }
            .tpz-return-workspace-modal .fi-modal-footer { border-top: 1px solid rgba(16, 91, 166, .1); }
            .dark .tpz-return-workspace-modal .fi-sc-section { border-color: rgba(147,197,253,.22); }
            .dark .tpz-return-workspace-modal .fi-sc-section-header { background: rgba(37,99,235,.14); }
            .dark .tpz-return-workspace-modal .fi-sc-section-header-heading { color: #dbeafe; }
        </style>
    @endonce
    <div class="tpz-workspace tpz-unified-workspace tpz-reference-workspace">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-center justify-between gap-4">
            <div class="tpz-native-workspace-heading">
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb">
                    <span>{{ str_replace(' Workspace', '', $workspace['title']) }}</span>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page">{{ $workspace['title'] }}</span>
                </nav>
                <div class="tpz-native-workspace-heading-main">
                    <span class="tpz-unified-module-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($workspace['icon'])" class="size-6" /></span>
                    <div>
                        <h1 class="tpz-workspace-title">{{ $workspace['title'] }}</h1>
                        <p class="tpz-workspace-subtitle">{{ $workspace['description'] }}</p>
                    </div>
                </div>
            </div>
            @if($this instanceof \App\Filament\Pages\ReturnsQcWorkspace)
                <div class="flex flex-wrap gap-2">
                    @if(($activeModule === 'overview' || ($selectedModuleData['label'] ?? null) === 'Customer Returns') && \App\Filament\Resources\CustomerReturns\CustomerReturnResource::canCreate())
                        <x-filament::button type="button" wire:click="mountAction('createCustomerReturn')" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Customer Return</x-filament::button>
                    @endif
                    @if(($selectedModuleData['label'] ?? null) === 'Marketplace Return Removals' && \App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource::canCreate())
                        <x-filament::button type="button" wire:click="mountAction('createMarketplaceRemoval')" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Marketplace Removal</x-filament::button>
                    @endif
                </div>
            @elseif($this instanceof \App\Filament\Pages\PurchasingWorkspace && \App\Filament\Pages\Purchasing\QuickStockPurchase::canAccess())
                <x-filament::button type="button" wire:click="mountAction('quickPurchase')">Quick Purchase</x-filament::button>
            @elseif($activeModule !== 'overview' && $selectedModuleData)
                <x-filament::button tag="a" :href="$selectedModuleData['url']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-arrow-up-right')">
                    Open {{ $selectedModuleData['label'] }}
                </x-filament::button>
            @elseif($quickActions->isNotEmpty())
                <div class="tpz-workspace-context-actions flex flex-wrap gap-2">
                    <x-filament::button tag="a" :href="$quickActions->first()['url']" :icon="\App\Support\SafeWorkspaceIcon::resolve($quickActions->first()['icon'])">
                        {{ $quickActions->first()['label'] }}
                    </x-filament::button>
                    @if($quickActions->count() > 1)
                        <details class="tpz-reference-actions-menu">
                            <summary>More Actions <span aria-hidden="true">⌄</span></summary>
                            <div class="tpz-reference-actions-options">
                                @foreach($quickActions->skip(1) as $action)
                                    <a href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </div>
            @endif
        </header>

        <nav class="tpz-workspace-tabs" aria-label="{{ $workspace['title'] }} sections" >
            <button type="button" class="tpz-workspace-tab {{ $activeModule === 'overview' ? 'is-active' : '' }}"
                aria-selected="{{ $activeModule === 'overview' ? 'true' : 'false' }}"
                wire:click="selectModule('overview')">
                <x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-squares-2x2')" class="tpz-native-tab-icon size-4" />
                Overview
            </button>
            @if($this instanceof \App\Filament\Pages\PurchasingWorkspace && \App\Filament\Resources\Products\ProductResource::canViewAny())
                <button type="button" wire:click="selectProducts" aria-selected="{{ $activeModule === 'products' ? 'true' : 'false' }}" class="tpz-workspace-tab {{ $activeModule === 'products' ? 'is-active' : '' }}">
                    <x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-cube')" class="tpz-native-tab-icon size-4" />
                    <span class="tpz-native-tab-label">Products ({{ number_format($purchasingProductCount) }})</span>
                </button>
            @endif
            @foreach($moduleTabs as $index => $tab)
                <button type="button" wire:key="workspace-tab-{{ $index }}"
                    wire:click="selectModule('{{ $index }}')"
                    aria-selected="{{ $activeModule === (string) $index ? 'true' : 'false' }}"
                    class="tpz-workspace-tab {{ $activeModule === (string) $index ? 'is-active' : '' }}">
                    <x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::moduleIcon($tab['label'], $tab['icon']))" class="tpz-native-tab-icon size-4" />
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

        @if($this instanceof \App\Filament\Pages\PurchasingWorkspace && $activeModule === 'products')
            @include('filament.pages.partials.workspace-products')
        @endif
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
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($metric['icon'])" class="size-5" /></span>
                                <span class="tpz-native-overview-label">{{ $metric['label'] }}</span>
                                <strong>{{ number_format($metric['value']) }}</strong>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <div class="tpz-native-overview-columns">
                        <section class="tpz-native-list-panel" aria-label="Needs Attention" x-data="{ expanded: true }">
                            <div class="tpz-native-panel-title">
                                <span class="tpz-reference-target-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" width="30" height="30"><circle cx="14" cy="17" r="11" stroke="currentColor" stroke-width="2.3"/><circle cx="14" cy="17" r="6" stroke="currentColor" stroke-width="2.2"/><circle cx="14" cy="17" r="1.7" fill="currentColor"/><path d="M14 17 24 7M22 4v6h6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                <div class="tpz-reference-panel-heading"><h2>Needs Attention</h2><small>Priority operational items in your authorized scope.</small></div>
                                <button type="button" class="tpz-reference-collapse" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()" aria-label="Toggle Needs Attention" aria-controls="tpz-needs-attention-items"><svg viewBox="0 0 20 20" fill="none" width="19" height="19" x-bind:class="expanded ? '' : 'is-collapsed'"><path d="m4 7 6 6 6-6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            </div>
                            <div class="tpz-reference-attention-grid" id="tpz-needs-attention-items" x-show="expanded">
                                @foreach($workspaceAttention as $alert)
                                    <a href="{{ $alert['url'] }}" class="tpz-reference-attention-row tpz-reference-attention-row--{{ \App\Support\WorkspaceVisuals::alertTone($alert['label']) }}">
                                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::alertIcon($alert['label']))" class="size-4" /></span>
                                        <span class="tpz-reference-alert-label" title="{{ $alert['label'] }}">{{ $alert['label'] }}</span>
                                        <strong>{{ number_format($alert['count']) }}</strong>
                                        <small>View</small>
                                    </a>
                                @endforeach
                                @if($workspaceAttention->isEmpty())
                                    <p class="tpz-native-no-preview">No urgent items in your authorized scope.</p>
                                @endif
                            </div>
                        </section>
                        <section class="tpz-native-list-panel" aria-label="Recent activity">
                            <div class="tpz-native-panel-title">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-clock')" class="size-5" /></span>
                                <h2>Recent Activity</h2>
                            </div>
                            <div class="tpz-native-recent-list">
                                @foreach($recentRecords as $record)
                                    <a href="{{ $record['url'] }}" wire:key="recent-{{ $loop->index }}">
                                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-document-text')" class="size-4" /></span>
                                        <span class="tpz-reference-movement-label">
                                            <strong>{{ $record['reference'] }}</strong>
                                            <small>{{ $record['source'] }}@if($record['status']) · {{ $record['status'] }}@endif</small>
                                        </span>
                                        <small>{{ $record['date'] }}</small>
                                    </a>
                                @endforeach
                                @if($recentRecords->isEmpty())
                                    <p class="tpz-native-no-preview">No recent records in your authorized scope.</p>
                                @endif
                            </div>
                        </section>
                </div>

            @if($recentRecords->isNotEmpty())
                <section class="tpz-native-list-panel tpz-reference-records-panel" aria-label="Recent authorized records">
                    <div class="tpz-native-panel-title">
                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-cube')" class="size-5" /></span>
                        <h2>Recent {{ str_replace(' Workspace', '', $workspace['title']) }} Records</h2>
                    </div>
                    <div class="tpz-native-record-table-wrap">
                        <table class="tpz-native-record-table">
                            <thead><tr><th>Reference</th><th>Module</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
                            <tbody>
                                @foreach($recentRecords as $record)
                                    <tr wire:key="recent-workspace-table-{{ $loop->index }}">
                                        <td><strong>{{ $record['reference'] }}</strong></td>
                                        <td>{{ $record['source'] }}</td>
                                        <td>@if($record['status'])<span class="tpz-reference-status-badge tpz-reference-status-badge--{{ \App\Support\WorkspaceVisuals::recordTone($record['status']) }}">{{ $record['status'] }}</span>@else — @endif</td>
                                        <td>{{ $record['date'] }}</td>
                                        <td><a href="{{ $record['url'] }}">View →</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @elseif($selectedModuleData)
            <section class="tpz-unified-overview-panel" aria-label="{{ $selectedModuleData['label'] }} details">
                <div class="tpz-native-module-header">
                    <span class="tpz-unified-module-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($selectedModuleData['icon'])" class="size-6" /></span>
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
                            <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($selectedModuleData['icon'])" class="size-5" /></span>
                            <span class="tpz-native-overview-label">{{ $selectedModuleData['label'] }} Records</span>
                            <strong>{{ number_format($selectedModuleData['total']) }}</strong>
                        </div>
                    </div>
                @endif
                @if($this instanceof \App\Filament\Pages\ReturnsQcWorkspace && in_array($selectedModuleData['label'], ['Customer Returns', 'Marketplace Return Removals'], true))
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ number_format($selectedModuleData['filteredTotal'] ?? 0) }} matching records (first 20 shown)</span>
                        <div class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
                            <div class="min-w-48 flex-1 sm:w-72 sm:flex-none">
                                <label for="tpz-return-record-search" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Search</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="tpz-return-record-search" type="search" wire:model.live.debounce.350ms="recordSearch" placeholder="Reference, SKU or product" aria-label="Search return records" />
                                </x-filament::input.wrapper>
                            </div>
                            <div class="min-w-36">
                                <label for="tpz-return-record-status" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Status</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="tpz-return-record-status" wire:model.live="recordStatus" aria-label="Filter return status">
                                        <option value="">All statuses</option>
                                        @php
                                            $statusEnum = $selectedModuleData['label'] === 'Customer Returns'
                                                ? \App\Enums\CustomerReturnStatus::class
                                                : \App\Enums\MarketplaceReturnRemovalStatus::class;
                                        @endphp
                                        @foreach($statusEnum::cases() as $statusOption)
                                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                            <x-filament::button type="button" color="gray" size="sm" wire:click="clearRecordFilters">Clear</x-filament::button>
                        </div>
                    </div>
                @endif
                @if($selectedModuleData['records']->isNotEmpty())
                    <div class="tpz-native-record-table-wrap">
                        <table class="tpz-native-record-table">
                            <thead><tr><th>Reference</th>@if($this instanceof \App\Filament\Pages\ReturnsQcWorkspace && $selectedModuleData['label'] === 'Customer Returns')<th>Product / SKU</th>@endif<th>Created</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                                @foreach($selectedModuleData['records'] as $record)
                                    <tr wire:key="selected-record-{{ $loop->index }}">
                                        <td><strong>{{ $record['reference'] }}</strong></td>
                                        @if($this instanceof \App\Filament\Pages\ReturnsQcWorkspace && $selectedModuleData['label'] === 'Customer Returns')
                                            <td class="min-w-64 max-w-md whitespace-normal break-words">{{ $record['products'] ?: '—' }}</td>
                                        @endif
                                        <td>{{ $record['date'] }}</td>
                                        <td><span class="tpz-reference-status-badge tpz-reference-status-badge--{{ \App\Support\WorkspaceVisuals::recordTone($record['status'] ?? '') }}">{{ $record['status'] ?? '—' }}</span></td>
                                        <td><a href="{{ $record['url'] }}">View →</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="tpz-native-no-preview">{{ $this instanceof \App\Filament\Pages\ReturnsQcWorkspace && in_array($selectedModuleData['label'], ['Customer Returns', 'Marketplace Return Removals'], true) ? ((filled($recordSearch) || filled($recordStatus)) ? 'No matching records in your authorized scope.' : 'No records in your authorized scope. Use Create above if permitted.') : 'Open the existing module to access its authorized records, filters, and actions.' }}</p>
                @endif
                <div class="tpz-native-module-footer">
                    <x-filament::button tag="a" :href="$selectedModuleData['url']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-arrow-up-right')">
                        Open full {{ $selectedModuleData['label'] }} module
                    </x-filament::button>
                </div>
            </section>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">All records, approvals, financial controls, and inventory responsibility restrictions continue through the existing ERP workflows.</p>
    </div>
</x-filament-panels::page>
