<x-filament-panels::page>
    <div class="tpz-workspace tpz-reference-inventory">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-start justify-between gap-4">
            <div>
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb"><span>Inventory & Warehouse</span><span aria-hidden="true">/</span><span aria-current="page">Inventory Workspace</span></nav>
                <h1 class="tpz-workspace-title">Inventory &amp; Warehouse</h1>
                <p class="tpz-workspace-subtitle">Available Stock, reservations and actions within your authorized responsibility.</p>
            </div>
            <div class="tpz-workspace-context-actions flex flex-wrap gap-2" aria-label="Actions for selected inventory section">
                @if($activeTab === 'extra' && $activeExtraModule !== null && isset($extraModules[$activeExtraModule]))
                    <x-filament::button tag="a" :href="$extraModules[$activeExtraModule]['url']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-arrow-up-right')">
                        Open {{ $extraModules[$activeExtraModule]['label'] }}
                    </x-filament::button>
                @elseif($activeTab === 'overview')
                    @if(isset($links['createRequest']))
                        <x-filament::button tag="a" :href="$links['createRequest']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Stock Request</x-filament::button>
                    @endif
                    @if(isset($links['transfers']))
                        <details class="tpz-reference-actions-menu">
                            <summary>More Actions <span aria-hidden="true">⌄</span></summary>
                            <div class="tpz-reference-actions-options">
                                <a href="{{ $links['transfers'] }}">Stock Transfers</a>
                            </div>
                        </details>
                    @endif
                @elseif($activeTab === 'products')
                    @if(isset($links['createProduct']))
                        <x-filament::button tag="a" :href="$links['createProduct']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Product</x-filament::button>
                    @endif
                    @if(isset($links['products']))
                        <x-filament::button tag="a" color="gray" :href="$links['products']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-cube')">All Products</x-filament::button>
                    @endif
                @elseif($activeTab === 'inventory' && isset($links['inventory']))
                    <x-filament::button tag="a" :href="$links['inventory']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-archive-box')">Open Inventory</x-filament::button>
                @elseif($activeTab === 'myInventory' && isset($links['myInventory']))
                    <x-filament::button tag="a" :href="$links['myInventory']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-user')">My Inventory</x-filament::button>
                @elseif($activeTab === 'requests')
                    @if(isset($links['createRequest']))
                        <x-filament::button tag="a" :href="$links['createRequest']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Stock Request</x-filament::button>
                    @endif
                    @if(isset($links['requests']))
                        <x-filament::button tag="a" color="gray" :href="$links['requests']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-clipboard-document-list')">All Requests</x-filament::button>
                    @endif
                @elseif($activeTab === 'transfers')
                    @if(isset($links['createTransfer']))
                        <x-filament::button tag="a" :href="$links['createTransfer']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Stock Transfer</x-filament::button>
                    @endif
                    @if(isset($links['transfers']))
                        <x-filament::button tag="a" color="gray" :href="$links['transfers']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-truck')">All Transfers</x-filament::button>
                    @endif
                @elseif($activeTab === 'adjustments' && isset($links['adjustments']))
                    <x-filament::button tag="a" :href="$links['adjustments']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-adjustments-horizontal')">Open Stock Adjustments</x-filament::button>
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Inventory workspace sections">
            <button type="button" class="tpz-workspace-tab {{ $activeTab === 'overview' ? 'is-active' : '' }}" aria-selected="{{ $activeTab === 'overview' ? 'true' : 'false' }}" wire:click="setTab('overview')"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-squares-2x2')" class="tpz-native-tab-icon size-4" /> Overview</button>
            @foreach (['products' => 'Products', 'inventory' => 'Inventory', 'myInventory' => 'My Inventory', 'requests' => 'Requests', 'transfers' => 'Transfers', 'adjustments' => 'Adjustments'] as $key => $label)
                @if(isset($links[$key]))
                    <button type="button" class="tpz-workspace-tab {{ $activeTab === $key ? 'is-active' : '' }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve((['products' => 'heroicon-o-cube', 'inventory' => 'heroicon-o-archive-box', 'myInventory' => 'heroicon-o-user', 'requests' => 'heroicon-o-clipboard-document-list', 'transfers' => 'heroicon-o-arrows-right-left', 'adjustments' => 'heroicon-o-adjustments-horizontal'][$key] ?? 'heroicon-o-document-text'))" class="tpz-native-tab-icon size-4" /> <span class="tpz-native-tab-copy"><span>{{ $label }}</span>@if(isset($tabCounts[$key]))<strong class="tpz-native-tab-count">{{ number_format($tabCounts[$key]) }}</strong>@endif</span></button>
                @endif
            @endforeach
            @foreach($extraModules as $index => $module)
                <button type="button" wire:key="extra-module-tab-{{ $index }}"
                    class="tpz-workspace-tab {{ $activeTab === 'extra' && $activeExtraModule === $index ? 'is-active' : '' }}"
                    aria-selected="{{ $activeTab === 'extra' && $activeExtraModule === $index ? 'true' : 'false' }}"
                    wire:click="selectExtraModule({{ $index }})"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($module['icon'])" class="tpz-native-tab-icon size-4" /> {{ $module['label'] }}</button>
            @endforeach
        </nav>

        @if($activeTab === 'overview' && $inventoryCards->isNotEmpty())
            <section class="tpz-unified-overview-panel" aria-label="Inventory operational overview">
                <div class="tpz-unified-section-title">
                    <h2>Operational Overview</h2>
                    <span>Live, permission-scoped totals</span>
                </div>
                <div class="tpz-native-overview-metrics tpz-native-overview-metrics--primary">
                    @php
                        $cardDefinitions = [
                        ['key' => 'inventory_units', 'icon' => 'heroicon-o-cube', 'tone' => 'blue'],
                        ['key' => 'sellable_inventory', 'icon' => 'heroicon-o-shopping-bag', 'tone' => 'green'],
                        ['key' => 'inventory_value', 'icon' => 'heroicon-o-chart-bar', 'tone' => 'violet'],
                        ['key' => 'low_stock', 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'amber'],
                        ['key' => 'out_of_stock', 'icon' => 'heroicon-o-x-circle', 'tone' => 'rose'],
                        ['key' => 'damaged', 'icon' => 'heroicon-o-shield-exclamation', 'tone' => 'rose'],
                        ['key' => 'qc_pending', 'icon' => 'heroicon-o-magnifying-glass', 'tone' => 'amber'],
                        ['key' => 'marketplace_inventory', 'icon' => 'heroicon-o-building-storefront', 'tone' => 'blue'],
                        ];
                    @endphp
                    @foreach($cardDefinitions as $definition)
                        @php
                            $card = $inventoryCards->get($definition['key']);
                        @endphp
                        @if($card && $card['url'])
                            <a href="{{ $card['url'] }}"
                                class="tpz-native-overview-metric tpz-native-overview-metric--{{ $definition['tone'] }}"
                                title="{{ $card['description'] ?? $card['label'] }}">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($definition['icon'])" class="size-5" /></span>
                                <span class="tpz-native-overview-label">{{ $card['label'] }}</span>
                                <strong>{{ $card['value'] }}</strong>
                            </a>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if($activeTab === 'overview' && ($inventoryAttention->isNotEmpty() || (isset($links['movements']) && $recentMovements->isNotEmpty())))
            <div class="tpz-native-overview-columns tpz-reference-inventory-panels">
                @if($inventoryAttention->isNotEmpty())
                    <section class="tpz-native-list-panel" aria-label="Needs Attention">
                        <div class="tpz-native-panel-title">
                            <span class="tpz-native-overview-icon tpz-reference-warning-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-exclamation-triangle')" class="size-5" /></span>
                            <h2>Needs Attention</h2>
                        </div>
                        <div class="tpz-reference-attention-grid">
                            @foreach($inventoryAttention as $alert)
                                <a href="{{ $alert['url'] }}" class="tpz-reference-attention-row tpz-reference-attention-row--{{ \App\Support\WorkspaceVisuals::alertTone($alert['label']) }}">
                                    <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::alertIcon($alert['label']))" class="size-4" /></span>
                                    <span class="tpz-reference-alert-label" title="{{ $alert['label'] }}">{{ $alert['label'] }}</span>
                                    <strong>{{ number_format($alert['count']) }}</strong>
                                    <small>View</small>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
                @if(isset($links['movements']))
                    <section class="tpz-native-list-panel" aria-label="Recent Inventory Activity">
                        <div class="tpz-native-panel-title">
                            <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-clock')" class="size-5" /></span>
                            <h2>Recent Inventory Activity</h2>
                            <a href="{{ $links['movements'] }}" class="tpz-reference-panel-view">View all →</a>
                        </div>
                        <div class="tpz-native-recent-list">
                            @forelse($recentMovements->take(5) as $movement)
                                @if(\App\Filament\Resources\StockMovements\StockMovementResource::canView($movement))
                                    <a wire:key="reference-movement-{{ $movement->id }}" href="{{ \App\Filament\Resources\StockMovements\StockMovementResource::getUrl('view', ['record' => $movement]) }}">
                                        <span class="tpz-native-overview-icon tpz-reference-movement-icon tpz-reference-tone--{{ \App\Support\WorkspaceVisuals::movementTone($movement->movement_type?->label() ?? 'Movement') }}"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::movementIcon($movement->movement_type?->label() ?? 'Movement'))" class="size-4" /></span>
                                        <span class="tpz-reference-movement-label">
                                            <strong>{{ $movement->product?->sku ?? $movement->reference ?? 'Stock movement' }}</strong>
                                            <small>{{ $movement->movement_type?->label() ?? 'Inventory movement' }} · {{ number_format($movement->quantity) }} unit(s)</small>
                                        </span>
                                        <small>{{ $movement->occurred_at?->diffForHumans() ?? '—' }}</small>
                                    </a>
                                @endif
                            @empty
                                <p class="tpz-native-no-preview">No authorized movements found.</p>
                            @endforelse
                        </div>
                    </section>
                @endif
            </div>
        @endif

        @if($activeTab === 'overview' && isset($links['movements']) && $recentMovements->isNotEmpty())
            <section class="tpz-native-list-panel tpz-reference-records-panel" aria-label="Recent Stock Updates">
                <div class="tpz-native-panel-title">
                    <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-cube')" class="size-5" /></span>
                    <h2>Recent Stock Updates</h2>
                    <a href="{{ $links['movements'] }}" class="tpz-reference-panel-view">View Inventory →</a>
                </div>
                <div class="tpz-native-record-table-wrap">
                    <table class="tpz-native-record-table">
                        <thead>
                            <tr><th>Reference</th><th>Product (SKU)</th><th>Warehouse</th><th>Type</th><th>Qty</th><th>Updated by</th><th>Date &amp; Time</th></tr>
                        </thead>
                        <tbody>
                            @foreach($recentMovements->take(5) as $movement)
                                @if(\App\Filament\Resources\StockMovements\StockMovementResource::canView($movement))
                                    <tr wire:key="recent-stock-update-{{ $movement->id }}">
                                        <td><a href="{{ \App\Filament\Resources\StockMovements\StockMovementResource::getUrl('view', ['record' => $movement]) }}">{{ $movement->reference ?? ('#'.$movement->id) }}</a></td>
                                        <td>{{ $movement->product?->name ?? 'Product' }} ({{ $movement->product?->sku ?? '—' }})</td>
                                        <td>{{ $movement->warehouse?->name ?? 'Warehouse' }}</td>
                                        <td><span class="tpz-reference-status-badge tpz-reference-status-badge--{{ \App\Support\WorkspaceVisuals::movementTone($movement->movement_type?->label() ?? 'Movement') }}"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::movementIcon($movement->movement_type?->label() ?? 'Movement'))" class="size-4" />{{ $movement->movement_type?->label() ?? 'Movement' }}</span></td>
                                        <td>{{ number_format($movement->quantity) }}</td>
                                        <td>{{ $movement->actor?->name ?? 'System' }}</td>
                                        <td>{{ $movement->occurred_at?->format('d M Y, H:i') ?? '—' }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if($canSeeSummary && $activeTab === 'overview')
            <x-filament::section>
                <div class="mb-4">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Low Stock</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Authorized products with sellable stock at or below the configured threshold ({{ $lowStockThreshold }} units).</p>
                </div>
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @forelse($lowStockProducts as $productRow)
                        <div class="flex min-w-0 items-start justify-between gap-3 rounded-xl border border-amber-100 bg-amber-50/60 p-3 dark:border-amber-900/40 dark:bg-amber-950/20">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $productRow['product']->sku }}</p>
                                <p class="line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $productRow['product']->name }}</p>
                                @if($canViewProducts && \App\Filament\Resources\Products\ProductResource::canView($productRow['product']))
                                    <a class="mt-2 inline-block text-xs font-semibold text-primary-700 hover:underline dark:text-primary-300" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $productRow['product']]) }}">View product →</a>
                                @endif
                            </div>
                            <x-filament::badge :color="$productRow['sellable'] <= 0 ? 'danger' : 'warning'">
                                {{ $productRow['sellable'] <= 0 ? 'Out of stock' : $productRow['sellable'] . ' sellable' }}
                            </x-filament::badge>
                        </div>
                    @empty
                        <p class="py-2 text-sm text-gray-500 dark:text-gray-400">No low-stock products in your authorized view.</p>
                    @endforelse
                </div>
            </x-filament::section>
        @endif

        @if(in_array($activeTab, ['overview', 'inventory'], true))
        <x-filament::section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Authorized Inventory</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Responsibility-scoped stock quantities. Search covers all matching records.</p>
                </div>
                <div class="w-full sm:w-72">
                    <x-filament::input.wrapper>
                        <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by SKU or product" aria-label="Search inventory" />
                    </x-filament::input.wrapper>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="tpz-workspace-table">
                    <thead>
                        <tr><th>SKU &amp; product</th><th>Warehouse</th><th class="text-right">Available</th><th class="text-right">Reserved</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        @forelse($inventory as $row)
                            <tr wire:key="inventory-{{ $row->id }}">
                                <td>
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ $row->product?->sku }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500">{{ $row->product?->name }}</div>
                                </td>
                                <td>{{ $row->warehouse?->name ?? '—' }}</td>
                                <td class="text-right font-semibold tabular-nums">{{ number_format($row->available_quantity) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($row->reserved_quantity) }}</td>
                                <td>
                                    @if($canViewProducts && $row->product && \App\Filament\Resources\Products\ProductResource::canView($row->product))
                                        <x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $row->product_id])">View product</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-sm text-gray-500">No inventory matches your search and responsibility scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($inventory instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                <div class="mt-4">{{ $inventory->links() }}</div>
            @endif
        </x-filament::section>
        @endif

        @if(in_array($activeTab, ['products', 'myInventory', 'requests', 'transfers'], true) && isset($links[$activeTab]))
            @php
                $resourceClass = match ($activeTab) {
                    'products' => \App\Filament\Resources\Products\ProductResource::class,
                    'myInventory' => \App\Filament\Resources\ProductInventories\ProductInventoryResource::class,
                    'requests' => \App\Filament\Resources\StockRequests\StockRequestResource::class,
                    'transfers' => \App\Filament\Resources\StockTransfers\StockTransferResource::class,
                };
            @endphp
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ ['products' => 'Products', 'myInventory' => 'My Inventory', 'requests' => 'Stock Requests', 'transfers' => 'Stock Transfers'][$activeTab] }}</h2>
                    <x-filament::button tag="a" size="sm" color="gray" :href="$links[$activeTab]">Open full module</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>Reference / SKU</th><th>Details</th><th>Last updated</th><th>Action</th></tr></thead>
                        <tbody>
                            @forelse($moduleRecords as $record)
                                <tr wire:key="native-module-{{ $activeTab }}-{{ $record->getKey() }}">
                                    <td class="font-semibold text-gray-950 dark:text-white">
                                        {{ $activeTab === 'products' ? $record->sku : ($activeTab === 'myInventory' ? ($record->product?->sku ?? '—') : ($record->reference ?? '—')) }}
                                    </td>
                                    <td>
                                        @if($activeTab === 'products')
                                            {{ $record->name }}
                                        @elseif($activeTab === 'myInventory')
                                            {{ $record->warehouse?->name ?? 'Warehouse' }} · {{ number_format($record->available_quantity ?? 0) }} available
                                        @else
                                            {{ str($record->status?->value ?? $record->status ?? 'Unknown')->headline() }}
                                        @endif
                                    </td>
                                    <td>{{ $record->updated_at?->format('d M Y') ?? '—' }}</td>
                                    <td>
                                        @if($resourceClass::canView($record))
                                            <x-filament::button tag="a" size="xs" color="gray" :href="$resourceClass::getUrl('view', ['record' => $record])">View</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-gray-500">No records in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($moduleRecords instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">{{ $moduleRecords->links() }}</div>
                @endif
            </x-filament::section>
        @endif

        @if($activeTab === 'adjustments' && isset($links['adjustments']))
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Stock Adjustments</h2>
                        <p class="mt-1 text-sm text-gray-500">Open the existing controlled adjustment form to make authorized stock changes.</p>
                    </div>
                    <x-filament::button tag="a" :href="$links['adjustments']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-arrow-up-right')">Open Stock Adjustments</x-filament::button>
                </div>
            </x-filament::section>
        @endif


        @if($activeTab === 'extra')
            @include('filament.pages.partials.workspace-extra-preview')
        @endif
    </div>
</x-filament-panels::page>
