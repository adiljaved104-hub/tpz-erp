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
                    @if(\App\Filament\Resources\StockRequests\StockRequestResource::canCreate())
                        <x-filament::button type="button" wire:click="mountAction('createStockRequest')" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Stock Request</x-filament::button>
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
                    @if(\App\Filament\Resources\StockRequests\StockRequestResource::canCreate())
                        <x-filament::button type="button" wire:click="mountAction('createStockRequest')" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Stock Request</x-filament::button>
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
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Inventory workspace sections" >
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
                    wire:click="selectExtraModule({{ $index }})"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::moduleIcon($module['label'], $module['icon']))" class="tpz-native-tab-icon size-4" /> {{ $module['label'] }}</button>
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
                    <section class="tpz-native-list-panel" aria-label="Needs Attention" x-data="{ expanded: true }">
                        <div class="tpz-native-panel-title">
                            <span class="tpz-reference-target-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" width="30" height="30"><circle cx="14" cy="17" r="11" stroke="currentColor" stroke-width="2.3"/><circle cx="14" cy="17" r="6" stroke="currentColor" stroke-width="2.2"/><circle cx="14" cy="17" r="1.7" fill="currentColor"/><path d="M14 17 24 7M22 4v6h6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <div class="tpz-reference-panel-heading"><h2>Needs Attention</h2><small>Priority operational items in your authorized scope.</small></div>
                                <button type="button" class="tpz-reference-collapse" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()" aria-label="Toggle Needs Attention" aria-controls="tpz-needs-attention-items"><svg viewBox="0 0 20 20" fill="none" width="19" height="19" x-bind:class="expanded ? '' : 'is-collapsed'"><path d="m4 7 6 6 6-6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                        </div>
                        <div class="tpz-reference-attention-grid" id="tpz-needs-attention-items" x-show="expanded">
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
                <div class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
                    <div class="w-full sm:w-64">
                        <label for="tpz-inventory-search" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Search SKU / Product</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="tpz-inventory-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by SKU or product" aria-label="Search inventory" />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label for="tpz-stock-filter" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Stock</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="tpz-stock-filter" wire:model.live="inventoryStockFilter" aria-label="Filter stock quantity">
                                <option value="in_stock">In stock only</option>
                                <option value="zero_stock">Zero stock only</option>
                                <option value="all">All stock</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    @if($inventoryHolderOptions !== [])
                        <div>
                            <label for="tpz-holder-filter" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Employee Stock Holder</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="tpz-holder-filter" wire:model.live="inventoryHolderFilter">
                                    <option value="">All employees</option>
                                    @foreach($inventoryHolderOptions as $holderId => $holderName)
                                        <option value="{{ $holderId }}">{{ $holderName }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                    @if(isset($links['adjustments']))
                        <x-filament::button tag="a" size="sm" color="gray" :href="$links['adjustments']">Stock Adjustments ↗</x-filament::button>
                    @endif
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="tpz-workspace-table">
                    <thead>
                        <tr><th>SKU &amp; product</th><th>Warehouse</th><th class="text-right">{{ $employeeInventoryView ? 'My Allocated' : 'Warehouse Stock' }}</th><th class="text-right">{{ $employeeInventoryView ? 'My Reserved' : 'Reserved' }}</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        @forelse($inventory as $row)
                            <tr wire:key="inventory-{{ $row->id }}">
                                <td>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white whitespace-normal break-words">{{ $row->product?->name ?? '—' }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row->product?->sku ?? '—' }}</div>
                                </td>
                                <td>{{ $row->warehouse?->name ?? '—' }}</td>
                                @php
                                    $personal = $employeeInventoryView ? $inventoryOwnBalances->get($row->id) : null;
                                @endphp
                                <td class="text-right font-semibold tabular-nums">{{ number_format($employeeInventoryView ? (int) ($personal?->allocated_quantity ?? 0) : $row->available_quantity) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($employeeInventoryView ? (int) ($personal?->reserved_quantity ?? 0) : $row->reserved_quantity) }}</td>
                                <td>
                                    <x-filament::button type="button" size="xs" color="gray" wire:click="openInventoryQuickView({{ $row->id }})">Quick View</x-filament::button>
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
                @if($activeTab === 'myInventory')
                    <div class="mb-4 flex flex-wrap items-end justify-end gap-2">
                        <div class="w-full sm:w-80">
                            <label for="tpz-my-inventory-search" class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Search</label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="tpz-my-inventory-search" type="search" wire:model.live.debounce.350ms="myInventorySearch" placeholder="Search by SKU or product" aria-label="Search My Inventory" />
                            </x-filament::input.wrapper>
                        </div>
                        <x-filament::button type="button" size="sm" color="gray" wire:click="clearMyInventorySearch">Clear</x-filament::button>
                    </div>
                @endif
                @if($activeTab === 'products')
                    <div class="mb-4">
                        <label for="tpz-product-search" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">Search products</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="tpz-product-search" type="search" wire:model.live.debounce.350ms="productSearch" placeholder="Search SKU or product name" autocomplete="off" />
                        </x-filament::input.wrapper>
                    </div>
                @endif
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead>
                            @if($activeTab === 'requests')
                                <tr><th>Stock Request Reference</th><th>Requested By</th><th>Purpose</th><th>Requested Products</th><th>Total Requested Quantity</th><th>Status</th><th>Requested At</th><th>Authorized Actions</th></tr>
                            @else
                                <tr><th>Reference / SKU</th><th>Details</th><th>Last updated</th><th>Action</th></tr>
                            @endif
                        </thead>
                        <tbody>
                            @forelse($moduleRecords as $record)
                                <tr wire:key="native-module-{{ $activeTab }}-{{ $record->getKey() }}">
                                    <td class="font-semibold text-gray-950 dark:text-white">
                                        {{ $activeTab === 'products' ? $record->sku : ($activeTab === 'myInventory' ? ($record->product?->sku ?? '—') : ($record->reference ?? '—')) }}
                                    </td>
                                    @if($activeTab === 'requests')
                                        <td>{{ $record->requester?->name ?? '—' }}</td>
                                        <td>{{ $record->purpose->getLabel() }}</td>
                                        <td class="min-w-52 whitespace-normal">
                                            <ul class="space-y-1 break-words">
                                                @foreach($record->items->take(2) as $item)
                                                    <li><strong>{{ $item->sku }}</strong> — {{ $item->product_name }} × {{ number_format($item->quantity) }}</li>
                                                @endforeach
                                            </ul>
                                            @if($record->items->count() > 2)
                                                <details class="mt-1">
                                                    <summary class="cursor-pointer text-primary-600 dark:text-primary-400">Show {{ $record->items->count() - 2 }} more products</summary>
                                                    <ul class="mt-1 space-y-1 break-words">
                                                        @foreach($record->items->skip(2) as $item)
                                                            <li><strong>{{ $item->sku }}</strong> — {{ $item->product_name }} × {{ number_format($item->quantity) }}</li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
                                        </td>
                                        <td class="tabular-nums">{{ number_format((int) $record->requested_quantity) }}</td>
                                        <td><x-filament::badge :color="$record->status->getColor()">{{ $record->status->getLabel() }}</x-filament::badge></td>
                                        <td>{{ app(\App\Services\UserTimezoneResolver::class)->format($record->created_at, 'd M Y, h:i A') ?? '—' }}</td>
                                    @else
                                    <td>
                                        @if($activeTab === 'products')
                                            {{ $record->name }}
                                        @elseif($activeTab === 'myInventory')
                                            <span class="block max-w-xl whitespace-normal break-words font-semibold text-gray-950 dark:text-white">{{ $record->product?->name ?? 'Product name unavailable' }}</span>
                                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $record->warehouse?->name ?? 'Warehouse' }} · {{ number_format($record->available_quantity ?? 0) }} available</span>
                                        @else
                                            {{ str($record->status?->value ?? $record->status ?? 'Unknown')->headline() }}
                                        @endif
                                    </td>
                                    <td>{{ $record->updated_at?->format('d M Y') ?? '—' }}</td>
                                    @endif
                                    <td>
                                        @if($resourceClass::canView($record))
                                            <div class="flex flex-wrap gap-2">
                                                @if($activeTab === 'myInventory')
                                                    <x-filament::button type="button" size="xs" color="gray" wire:click="openInventoryQuickView({{ (int) $record->getKey() }})">Quick View</x-filament::button>
                                                    <x-filament::button tag="a" size="xs" color="gray" :href="$resourceClass::getUrl('view', ['record' => $record])">Open full</x-filament::button>
                                                @else
                                                    <x-filament::button tag="a" size="xs" color="gray" :href="$resourceClass::getUrl('view', ['record' => $record])">View</x-filament::button>
                                                @endif
                                                @if($activeTab === 'requests' && $record instanceof \App\Models\StockRequest && $this->canExecuteWorkspaceRequest($record))
                                                    <x-filament::button type="button" size="xs" color="success" wire:click="prepareStockRequestExecution({{ $record->getKey() }})">Execute approved</x-filament::button>
                                                @endif
                                                @if($activeTab === 'requests' && $record instanceof \App\Models\StockRequest && $this->canApproveWorkspaceRequest($record))
                                                    <x-filament::button type="button" size="xs" color="primary" wire:click="prepareSourceDecision({{ $record->getKey() }})">Review sources</x-filament::button>
                                                @endif
                                                @if($activeTab === 'requests' && $record instanceof \App\Models\StockRequest && $this->canOwnerApproveTransfer($record))
                                                    <x-filament::button type="button" size="xs" color="warning" wire:click="prepareOwnerApproveTransfer({{ $record->getKey() }})">Approve &amp; Transfer</x-filament::button>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $activeTab === 'requests' ? 8 : 4 }}" class="py-8 text-center text-gray-500">No records in your authorized scope.</td></tr>
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
            @livewire(\App\Filament\Pages\Inventory\StockAdjustments::class, ['embedded' => true], key('workspace-stock-adjustments'))
        @endif


        @if($activeTab === 'extra' && $activeExtraModule !== null && isset($extraModules[$activeExtraModule]) && $extraModules[$activeExtraModule]['label'] === 'My Allocated Stock' && $canSeeSummary)
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-base font-semibold text-gray-950 dark:text-white">My Allocated Stock</h2>
                        <p class="text-xs text-gray-500">Your own allocated quantities. Read-only and permission-scoped.</p></div>
                    <x-filament::button tag="a" color="gray" size="sm" :href="$extraModules[$activeExtraModule]['url']">Open full module</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>SKU / Product</th><th>Location</th><th class="text-right">Allocated</th><th class="text-right">Reserved</th><th class="text-right">Available</th></tr></thead>
                        <tbody>
                            @forelse($allocatedRows as $balance)
                                <tr wire:key="allocated-stock-{{ $balance->id }}">
                                    <td><strong>{{ $balance->inventory?->product?->sku ?? '—' }}</strong><span class="block whitespace-normal">{{ $balance->inventory?->product?->name ?? '—' }}</span></td>
                                    <td>{{ $balance->inventory?->warehouse?->name ?? '—' }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($balance->allocated_quantity) }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($balance->reserved_quantity) }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($balance->availableQuantity()) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-gray-500">No personal stock allocations in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @elseif($activeTab === 'extra' && $activeExtraModule !== null && isset($extraModules[$activeExtraModule]) && $extraModules[$activeExtraModule]['label'] === 'Inventory by Location' && $canSeeSummary)
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-base font-semibold text-gray-950 dark:text-white">Inventory by Location</h2>
                        <p class="text-xs text-gray-500">Authorized stock balances by warehouse and marketplace location. Read-only.</p></div>
                    <x-filament::button tag="a" color="gray" size="sm" :href="$extraModules[$activeExtraModule]['url']">Open full module</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>SKU / Product</th><th>Location</th><th class="text-right">Available</th><th class="text-right">Reserved</th><th class="text-right">Sellable</th></tr></thead>
                        <tbody>
                            @forelse($locationRows as $index => $row)
                                <tr wire:key="location-balance-{{ $index }}">
                                    <td><strong>{{ $row['sku'] }}</strong><span class="block whitespace-normal">{{ $row['product'] }}</span></td>
                                    <td>{{ $row['location'] }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($row['available']) }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($row['reserved']) }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($row['sellable']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-8 text-center text-gray-500">No location balances in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @elseif($activeTab === 'extra')
            @include('filament.pages.partials.workspace-extra-preview')
        @endif
    </div>

    @if($quickViewInventory)
        <div class="fixed inset-0 z-[100] flex justify-end" wire:key="inventory-quick-view-panel">
            <button type="button" class="absolute inset-0 bg-gray-950/60" wire:click="closeInventoryQuickView" aria-label="Close inventory quick view"></button>
            <aside role="dialog" aria-modal="true" aria-labelledby="inventory-quick-view-title"
                class="relative z-10 flex h-full w-full max-w-xl flex-col overflow-y-auto bg-white p-5 shadow-2xl dark:bg-gray-900 sm:p-7">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 pb-4 dark:border-gray-700">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Read-only Inventory · Quick View</p>
                        <h2 id="inventory-quick-view-title" class="mt-1 text-lg font-bold text-gray-950 dark:text-white">{{ $quickViewInventory['sku'] }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $quickViewInventory['name'] }}</p>
                    </div>
                    <x-filament::button color="gray" size="sm" wire:click="closeInventoryQuickView">Close</x-filament::button>
                </div>
                <dl class="mt-5 grid grid-cols-2 gap-4 text-sm">
                    <div class="col-span-2"><dt class="text-gray-500">Warehouse</dt><dd class="font-semibold text-gray-950 dark:text-white">{{ $quickViewInventory['warehouse'] }}</dd></div>
                    <div><dt class="text-gray-500">{{ $quickViewInventory['personal'] ? 'My Allocated' : 'Warehouse Stock' }}</dt><dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($quickViewInventory['available']) }}</dd></div>
                    <div><dt class="text-gray-500">{{ $quickViewInventory['personal'] ? 'My Reserved' : 'Reserved' }}</dt><dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($quickViewInventory['reserved']) }}</dd></div>
                    @unless($quickViewInventory['personal'])<div><dt class="text-gray-500">Damaged</dt><dd class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($quickViewInventory['damaged']) }}</dd></div>@endunless
                </dl>
                @if(count($quickViewInventory['reservationRows']) > 0)
                    <h3 class="mt-7 border-b border-gray-200 pb-2 font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Active reservation details (authorized scope)</h3>
                    <div class="mt-3 space-y-2">
                        @foreach($quickViewInventory['reservationRows'] as $reservation)
                            <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                <div>
                                    <p class="font-semibold">{{ $reservation['reference'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $reservation['context'] }} · {{ $reservation['reserved_at'] }}</p>
                                </div>
                                <span class="tabular-nums">Qty {{ number_format($reservation['quantity']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @elseif($quickViewInventory['reserved'] > 0)
                    <p class="mt-5 text-xs text-gray-500">The total reserved quantity is displayed above; no detailed reservation rows are visible under your access scope.</p>
                @endif
                @if($quickViewInventory['movementsAllowed'])
                    <h3 class="mt-7 border-b border-gray-200 pb-2 font-semibold text-gray-950 dark:border-gray-700 dark:text-white">Recent permitted movements</h3>
                    <div class="mt-3 space-y-2">
                        @forelse($quickViewInventory['movements'] as $movement)
                            <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                <div>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $movement['reference'] }}</p>
                                    <p class="text-gray-600 dark:text-gray-300">{{ $movement['type'] }} · {{ $movement['date'] }}</p>
                                </div>
                                <span class="shrink-0 font-semibold tabular-nums">{{ number_format($movement['quantity']) }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No recent movements within your scope.</p>
                        @endforelse
                    </div>
                @endif
                <div class="mt-auto flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                    <x-filament::button color="gray" wire:click="closeInventoryQuickView">Back to workspace</x-filament::button>
                    @if($quickViewInventory['productUrl'])
                        <x-filament::button tag="a" :href="$quickViewInventory['productUrl']">Open full product</x-filament::button>
                    @endif
                </div>
            </aside>
        </div>
    @endif

    @if($pendingExecutionRequest)
        <div class="fixed inset-0 z-[110] flex items-center justify-center p-4" wire:key="stock-request-execution-confirmation">
            <button type="button" class="absolute inset-0 bg-gray-950/60" wire:click="cancelStockRequestExecution" aria-label="Cancel Stock Request execution"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="stock-request-execution-title"
                class="relative z-10 w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl dark:bg-gray-900 sm:p-7">
                <h2 id="stock-request-execution-title" class="text-lg font-bold text-gray-950 dark:text-white">Execute approved Stock Request?</h2>
                <p class="mt-2 font-semibold text-gray-900 dark:text-gray-100">{{ $pendingExecutionRequest['reference'] }}</p>
                <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">This action reserves or transfers approved stock. It uses the existing audited execution workflow and cannot be undone here.</p>
                <div class="mt-4 max-h-48 space-y-2 overflow-y-auto text-sm text-gray-700 dark:text-gray-300">
                    @foreach($pendingExecutionRequest['summary'] as $line)
                        <p class="rounded-lg border border-gray-200 p-2 dark:border-gray-700">{{ $line }}</p>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-filament::button color="gray" wire:click="cancelStockRequestExecution">Cancel</x-filament::button>
                    <x-filament::button color="success" wire:click="executePreparedStockRequest" wire:loading.attr="disabled" wire:target="executePreparedStockRequest">Confirm &amp; Execute</x-filament::button>
                </div>
            </section>
        </div>
    @endif

    @if($pendingSourceDecision)
        <div class="fixed inset-0 z-[110] flex items-center justify-center p-4" wire:key="stock-source-decision-confirmation">
            <button type="button" class="absolute inset-0 bg-gray-950/60" wire:click="cancelSourceDecision" aria-label="Cancel source decision"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="stock-source-decision-title"
                class="relative z-10 w-full max-w-lg rounded-xl bg-white p-5 shadow-2xl dark:bg-gray-900 sm:p-7">
                <h2 id="stock-source-decision-title" class="text-lg font-bold text-gray-950 dark:text-white">Review Stock Request sources</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $pendingSourceDecision['reference'] }}</p>
                <div class="mt-5 space-y-4">
                    <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100" for="workspace-source-line">Authorized stock source</label>
                    <select id="workspace-source-line" wire:model="pendingSourceDecisionLineId"
                        class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white">
                        <option value="">Select a stock source</option>
                        @foreach($pendingSourceDecision['options'] as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <fieldset class="flex gap-5 text-sm text-gray-900 dark:text-white">
                        <legend class="mb-2 font-semibold">Decision</legend>
                        <label><input type="radio" wire:model.live="sourceDecision" value="approved"> Approve</label>
                        <label><input type="radio" wire:model.live="sourceDecision" value="rejected"> Reject</label>
                    </fieldset>
                    @if($sourceDecision === 'approved')
                        <label class="block text-sm font-semibold text-gray-900 dark:text-white" for="workspace-source-quantity">Quantity to approve</label>
                        <input id="workspace-source-quantity" type="number" min="1" step="1" wire:model="sourceApprovedQuantity"
                            class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white">
                    @endif
                    <label class="block text-sm font-semibold text-gray-900 dark:text-white" for="workspace-source-note">
                        {{ $sourceDecision === 'rejected' ? 'Rejection reason (required)' : 'Approval note (optional)' }}
                    </label>
                    <textarea id="workspace-source-note" wire:model="sourceDecisionNote" maxlength="2000" rows="3"
                        class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white"></textarea>
                    <p class="text-xs text-gray-500">Approving only records a source decision. Stock is not reserved or transferred until authorized execution.</p>
                </div>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-filament::button color="gray" wire:click="cancelSourceDecision">Cancel</x-filament::button>
                    <x-filament::button color="success" wire:click="submitSourceDecision" wire:loading.attr="disabled" wire:target="submitSourceDecision">
                        Confirm source decision
                    </x-filament::button>
                </div>
            </section>
        </div>
    @endif

    @if($pendingOwnerTransfer)
        <div class="fixed inset-0 z-[110] flex items-center justify-center p-4" wire:key="owner-approve-transfer-dialog">
            <button type="button" class="absolute inset-0 bg-gray-950/60" wire:click="cancelOwnerApproveTransfer" aria-label="Cancel owner transfer"></button>
            <section role="dialog" aria-modal="true" aria-labelledby="owner-transfer-title"
                class="relative z-10 w-full max-w-xl rounded-xl bg-white p-5 shadow-2xl dark:bg-gray-900 sm:p-7">
                <h2 id="owner-transfer-title" class="text-lg font-bold text-gray-950 dark:text-white">Owner — Approve &amp; Transfer</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $pendingOwnerTransfer['reference'] }}</p>
                <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                    Permanent transfer only. Previously approved, untransferred stock: <strong>{{ number_format($pendingOwnerTransfer['alreadyApproved']) }}</strong>.
                    Pending sources are set to zero by default. Stock allocations will change only after confirmation.
                </p>
                <div class="mt-4 max-h-64 space-y-3 overflow-y-auto">
                    @forelse($pendingOwnerTransfer['lines'] as $line)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="min-w-0 text-sm text-gray-900 dark:text-gray-100">
                                <p class="font-semibold">{{ $line['label'] }}</p>
                                <p class="text-gray-500">Remaining request: {{ number_format($line['remaining']) }}</p>
                            </div>
                            <label class="text-sm text-gray-900 dark:text-gray-100" for="owner-transfer-{{ $line['id'] }}">
                                Approve now
                                <input id="owner-transfer-{{ $line['id'] }}" type="number" step="1" min="0" max="{{ $line['remaining'] }}"
                                    wire:model="ownerTransferQuantities.{{ $line['id'] }}"
                                    class="mt-1 block w-24 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white">
                            </label>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No pending stock sources. Only already approved quantities can be transferred.</p>
                    @endforelse
                </div>
                <p class="mt-4 text-xs text-gray-500">Only the selected new quantities plus previously approved, untransferred units will transfer. The operation uses the existing Owner-only audited transaction and cannot be undone here.</p>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <x-filament::button color="gray" wire:click="cancelOwnerApproveTransfer">Cancel</x-filament::button>
                    <x-filament::button color="success" wire:click="submitOwnerApproveTransfer" wire:loading.attr="disabled" wire:target="submitOwnerApproveTransfer">Confirm Approve &amp; Transfer</x-filament::button>
                </div>
            </section>
        </div>
    @endif
</x-filament-panels::page>
