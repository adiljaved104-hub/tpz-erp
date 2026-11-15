<x-filament-panels::page>
    <div class="tpz-workspace tpz-reference-sales">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-start justify-between gap-4">
            <div>
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb"><span>Sales</span><span aria-hidden="true">/</span><span aria-current="page">Sales Workspace</span></nav>
                <h1 class="tpz-workspace-title">Sales Workspace</h1>
                <p class="tpz-workspace-subtitle">Orders, Web Sales and quotation work within your authorized scope.</p>
            </div>
            <div class="tpz-workspace-context-actions flex flex-wrap gap-2" aria-label="Actions for selected sales section">
                @if($activeTab === 'extra' && $activeExtraModule !== null && isset($extraModules[$activeExtraModule]))
                    <x-filament::button tag="a" :href="$extraModules[$activeExtraModule]['url']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-arrow-up-right')">
                        Open {{ $extraModules[$activeExtraModule]['label'] }}
                    </x-filament::button>
                @elseif($activeTab === 'overview')
                    @if(isset($links['createOrder']))
                        <x-filament::button tag="a" :href="$links['createOrder']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Sales Order</x-filament::button>
                    @endif
                    @if(isset($links['dashboard']))
                        <details class="tpz-reference-actions-menu">
                            <summary>More Actions <span aria-hidden="true">⌄</span></summary>
                            <div class="tpz-reference-actions-options">
                                <a href="{{ $links['dashboard'] }}">Web Sales Dashboard</a>
                            </div>
                        </details>
                    @endif
                @elseif($activeTab === 'orders')
                    @if(isset($links['createOrder']))
                        <x-filament::button tag="a" :href="$links['createOrder']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Sales Order</x-filament::button>
                    @endif
                    @if(isset($links['orders']))
                        <x-filament::button tag="a" color="gray" :href="$links['orders']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-shopping-bag')">All Sales Orders</x-filament::button>
                    @endif
                @elseif($activeTab === 'webSales')
                    @if(isset($links['createWebSale']))
                        <x-filament::button tag="a" :href="$links['createWebSale']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Web Sale</x-filament::button>
                    @endif
                    @if(isset($links['dashboard']))
                        <x-filament::button tag="a" color="gray" :href="$links['dashboard']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-chart-bar')">Web Sales Dashboard</x-filament::button>
                    @elseif(isset($links['webSales']))
                        <x-filament::button tag="a" color="gray" :href="$links['webSales']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-globe-alt')">All Web Sales</x-filament::button>
                    @endif
                @elseif($activeTab === 'quotations')
                    @if(isset($links['createQuotation']))
                        <x-filament::button tag="a" :href="$links['createQuotation']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-plus')">Create Quotation</x-filament::button>
                    @endif
                    @if(isset($links['quotations']))
                        <x-filament::button tag="a" color="gray" :href="$links['quotations']" :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-document-text')">All Quotations</x-filament::button>
                    @endif
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Sales workspace sections" tabindex="0" title="Scroll horizontally or use the left/right arrow keys to see more modules" x-on:keydown.arrow-right.stop="$el.scrollBy({left: 190, behavior: 'smooth'})" x-on:keydown.arrow-left.stop="$el.scrollBy({left: -190, behavior: 'smooth'})">
            <button type="button" class="tpz-workspace-tab {{ $activeTab === 'overview' ? 'is-active' : '' }}" aria-selected="{{ $activeTab === 'overview' ? 'true' : 'false' }}" wire:click="setTab('overview')"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-squares-2x2')" class="tpz-native-tab-icon size-4" /> Overview</button>
            @foreach (['orders' => 'Sales Orders', 'webSales' => 'Web Sales', 'quotations' => 'Quotations'] as $key => $label)
                @if(isset($links[$key]))
                    <button type="button" class="tpz-workspace-tab {{ $activeTab === $key ? 'is-active' : '' }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve((['orders' => 'heroicon-o-shopping-bag', 'webSales' => 'heroicon-o-globe-alt', 'quotations' => 'heroicon-o-document-text'][$key] ?? 'heroicon-o-document-text'))" class="tpz-native-tab-icon size-4" /> <span class="tpz-native-tab-copy"><span>{{ $label }}</span>@if(isset($salesCounts[$key]))<strong class="tpz-native-tab-count">{{ number_format($salesCounts[$key]) }}</strong>@endif</span></button>
                @endif
            @endforeach
            @foreach($extraModules as $index => $module)
                <button type="button" wire:key="extra-module-tab-{{ $index }}"
                    class="tpz-workspace-tab {{ $activeTab === 'extra' && $activeExtraModule === $index ? 'is-active' : '' }}"
                    aria-selected="{{ $activeTab === 'extra' && $activeExtraModule === $index ? 'true' : 'false' }}"
                    wire:click="selectExtraModule({{ $index }})"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($module['icon'])" class="tpz-native-tab-icon size-4" /> {{ $module['label'] }}</button>
            @endforeach
        </nav>

        @if($activeTab === 'overview')
            <section class="tpz-unified-overview-panel">
                <div class="tpz-unified-section-title">
                    <h2>Operational Overview</h2>
                    <span>Live, permission-scoped totals</span>
                </div>
                <div class="tpz-native-overview-metrics tpz-native-overview-metrics--primary">
                    @php
                        $salesMetrics = [
                        ['key' => 'orders', 'label' => 'Sales Orders', 'icon' => 'heroicon-o-shopping-bag', 'tone' => 'blue', 'url' => $links['orders'] ?? null],
                        ['key' => 'webSales', 'label' => 'Web Sales', 'icon' => 'heroicon-o-globe-alt', 'tone' => 'green', 'url' => $links['webSales'] ?? null],
                        ['key' => 'quotations', 'label' => 'Quotations', 'icon' => 'heroicon-o-document-text', 'tone' => 'amber', 'url' => $links['quotations'] ?? null],
                        ['key' => 'taxInvoices', 'label' => 'Tax Invoices', 'icon' => 'heroicon-o-receipt-percent', 'tone' => 'violet', 'url' => \App\Filament\Resources\TaxInvoices\TaxInvoiceResource::canViewAny() ? \App\Filament\Resources\TaxInvoices\TaxInvoiceResource::getUrl() : null],
                        ];
                    @endphp
                    @foreach($salesMetrics as $metric)
                        @if(isset($salesCounts[$metric['key']]) && $metric['url'])
                            <a href="{{ $metric['url'] }}" class="tpz-native-overview-metric tpz-native-overview-metric--{{ $metric['tone'] }}">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve($metric['icon'])" class="size-5" /></span>
                                <span class="tpz-native-overview-label">{{ $metric['label'] }}</span>
                                <strong>{{ number_format($salesCounts[$metric['key']]) }}</strong>
                            </a>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if($activeTab === 'overview')
            <div class="tpz-native-overview-columns tpz-reference-sales-panels">
                    <section class="tpz-native-list-panel" aria-label="Sales Needs Attention" x-data="{ expanded: true }">
                            <div class="tpz-native-panel-title">
                                <span class="tpz-reference-target-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" width="30" height="30"><circle cx="14" cy="17" r="11" stroke="currentColor" stroke-width="2.3"/><circle cx="14" cy="17" r="6" stroke="currentColor" stroke-width="2.2"/><circle cx="14" cy="17" r="1.7" fill="currentColor"/><path d="M14 17 24 7M22 4v6h6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                <div class="tpz-reference-panel-heading"><h2>Needs Attention</h2><small>Priority operational items in your authorized scope.</small></div>
                                <button type="button" class="tpz-reference-collapse" x-on:click="expanded = !expanded" x-bind:aria-expanded="expanded.toString()" aria-label="Toggle Needs Attention" aria-controls="tpz-needs-attention-items"><svg viewBox="0 0 20 20" fill="none" width="19" height="19" x-bind:class="expanded ? '' : 'is-collapsed'"><path d="m4 7 6 6 6-6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                                <span class="tpz-reference-panel-note">Authorized operational items</span>
                            </div>
                            <div class="tpz-reference-attention-grid" id="tpz-needs-attention-items" x-show="expanded">
                                @foreach($salesAttention as $alert)
                                    <a class="tpz-reference-attention-row tpz-reference-attention-row--{{ \App\Support\WorkspaceVisuals::alertTone($alert['label']) }}" href="{{ $alert['url'] }}">
                                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(\App\Support\WorkspaceVisuals::alertIcon($alert['label']))" class="size-4" /></span>
                                        <span class="tpz-reference-alert-label" title="{{ $alert['label'] }}">{{ $alert['label'] }}</span>
                                        <strong>{{ number_format($alert['count']) }}</strong>
                                        <small>View</small>
                                    </a>
                                @endforeach
                                @if($salesAttention->isEmpty())
                                    <p class="tpz-native-no-preview">No sales-related alerts in your authorized scope.</p>
                                @endif
                            </div>
                        </section>
                        <section class="tpz-native-list-panel" aria-label="Recent Sales Activity">
                            <div class="tpz-native-panel-title">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-clock')" class="size-5" /></span>
                                <h2>Recent Sales Activity</h2>
                                @if(isset($links['orders']))
                                    <a class="tpz-reference-panel-view" href="{{ $links['orders'] }}">View all →</a>
                                @elseif(isset($links['webSales']))
                                    <a class="tpz-reference-panel-view" href="{{ $links['webSales'] }}">View all →</a>
                                @endif
                            </div>
                            <div class="tpz-native-recent-list">
                                @foreach($salesRecentUpdates->take(5) as $update)
                                    <a href="{{ $update['url'] }}" wire:key="recent-sales-{{ $loop->index }}">
                                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-shopping-bag')" class="size-4" /></span>
                                        <span class="tpz-reference-movement-label">
                                            <strong>{{ $update['reference'] }} · {{ $update['status'] }}</strong>
                                            <small>{{ $update['source'] }} · {{ number_format($update['units']) }} unit(s)</small>
                                        </span>
                                        <small>{{ $update['date'] }}</small>
                                    </a>
                                @endforeach
                                @if($salesRecentUpdates->isEmpty())
                                    <p class="tpz-native-no-preview">No recent sales activity in your authorized scope.</p>
                                @endif
                            </div>
                        </section>
                </div>

            <section class="tpz-native-list-panel tpz-reference-records-panel" aria-label="Recent Sales Updates">
                    <div class="tpz-native-panel-title">
                        <span class="tpz-native-overview-icon"><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve('heroicon-o-document-text')" class="size-5" /></span>
                        <h2>Recent Sales Updates</h2>
                        @if(isset($links['orders']))<a class="tpz-reference-panel-view" href="{{ $links['orders'] }}">View Sales Orders →</a>@endif
                    </div>
                    <div class="tpz-native-record-table-wrap">
                        <table class="tpz-native-record-table">
                            <thead><tr><th>Reference</th><th>Source / Channel</th><th>Customer</th><th>Units</th><th>Status</th><th>Order Date</th><th>Action</th></tr></thead>
                            <tbody>
                                @foreach($salesRecentUpdates as $update)
                                    <tr wire:key="sales-overview-update-{{ $loop->index }}">
                                        <td><strong>{{ $update['reference'] }}</strong></td>
                                        <td>{{ $update['source'] }}</td>
                                        <td>{{ $update['customer'] }}</td>
                                        <td>{{ number_format($update['units']) }}</td>
                                        <td><span class="tpz-reference-status-badge tpz-reference-status-badge--{{ \App\Support\WorkspaceVisuals::recordTone($update['status']) }}">{{ $update['status'] }}</span></td>
                                        <td>{{ $update['date'] }}</td>
                                        <td><a href="{{ $update['url'] }}">View →</a></td>
                                    </tr>
                                @endforeach
                                @if($salesRecentUpdates->isEmpty())
                                    <tr><td colspan="7" class="tpz-native-no-preview">No recent authorized sales records.</td></tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </section>
        @endif

        @if(in_array($activeTab, ['orders', 'webSales', 'quotations'], true))
            <div class="tpz-native-subtab-summary">
                <span class="tpz-native-overview-icon">
                    <x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(match($activeTab) {
                        'orders' => 'heroicon-o-shopping-bag',
                        'webSales' => 'heroicon-o-globe-alt',
                        default => 'heroicon-o-document-text',
                    })" class="size-5" />
                </span>
                <div>
                    <h2>{{ match($activeTab) {
                        'orders' => 'Sales Orders',
                        'webSales' => 'Web Sales',
                        default => 'Quotations',
                    } }}</h2>
                    <p>Manage and track records within your existing permissions.</p>
                </div>
                <span class="tpz-native-subtab-total">
                    <small>Total Records</small>
                    <strong>{{ number_format($salesCounts[$activeTab] ?? 0) }}</strong>
                </span>
            </div>
        @endif

        @if(in_array($activeTab, ['orders', 'webSales'], true))
        <x-filament::section>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Filter orders</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">The tables below update within your existing order permissions.</p>
                </div>
                <x-filament::button size="sm" color="gray" wire:click="clearFilters">Clear filters</x-filament::button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
                <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Order reference" aria-label="Search order reference" /></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="customer" placeholder="Web Sales customer" aria-label="Search Web Sales customer" /></x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="status" aria-label="Order status">
                        <option value="all">All statuses</option>
                        @foreach(\App\Enums\OrderStatus::cases() as $orderStatus)
                            <option value="{{ $orderStatus->value }}">{{ $orderStatus->getLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="channel" aria-label="Web Sales channel">
                        <option value="">All Web Sales channels</option>
                        @foreach(\App\Enums\WebSalesChannel::cases() as $channel)
                            <option value="{{ $channel->value }}">{{ $channel->getLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" aria-label="From date" /></x-filament::input.wrapper>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" aria-label="To date" /></x-filament::input.wrapper>
            </div>
        </x-filament::section>

        @endif

        @if(isset($links['orders']) && $activeTab === 'orders')
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Sales Orders</h2>
                    <a class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ $links['orders'] }}">Open full module →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>Reference</th><th>Order date</th><th>Source</th><th class="text-right">Items / Units</th>@if($canSeeOrderValues)<th class="text-right">Order total (AED)</th>@endif<th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            @forelse($orders as $order)
                                <tr wire:key="sales-order-{{ $order->id }}">
                                    <td class="font-semibold text-gray-950 dark:text-white">{{ $order->reference }}</td>
                                    <td>{{ $order->order_date?->format('d M Y') ?? '—' }}</td>
                                    <td>{{ $order->source ? str($order->source->value)->headline() : '—' }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($order->items_count ?? 0) }} / {{ number_format($order->total_units ?? 0) }}</td>
                                    @if($canSeeOrderValues)<td class="text-right font-semibold tabular-nums">{{ number_format((float) ($order->grand_total ?? 0), 2) }}</td>@endif
                                    <td><x-filament::badge :color="$order->status?->getColor() ?? 'gray'">{{ $order->status?->getLabel() ?? 'Unknown' }}</x-filament::badge></td>
                                    <td><x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order])">View</x-filament::button></td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $canSeeOrderValues ? 7 : 6 }}" class="py-8 text-center text-sm text-gray-500">No Sales Orders match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">{{ $orders->links() }}</div>
                @endif
            </x-filament::section>
        @endif

        @if(isset($links['webSales']) && $activeTab === 'webSales')
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Web Sales Orders</h2>
                    <a class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400" href="{{ $links['webSales'] }}">Open full module →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>Reference</th><th>Customer</th><th>Channel</th><th>Order date</th><th class="text-right">Units</th>@if($canSeeWebSalesValues)<th class="text-right">Order total (AED)</th>@endif<th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            @forelse($webSales as $order)
                                <tr wire:key="web-sales-order-{{ $order->id }}">
                                    <td class="font-semibold text-gray-950 dark:text-white">{{ $order->reference }}</td>
                                    <td>{{ $order->customer_name ?? '—' }}</td>
                                    <td>{{ $order->web_sales_channel?->getLabel() ?? '—' }}</td>
                                    <td>{{ $order->order_date?->format('d M Y') ?? '—' }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($order->units ?? 0) }}</td>
                                    @if($canSeeWebSalesValues)<td class="text-right font-semibold tabular-nums">{{ number_format((float) ($order->grand_total ?? 0), 2) }}</td>@endif
                                    <td><x-filament::badge :color="$order->status?->getColor() ?? 'gray'">{{ $order->status?->getLabel() ?? 'Unknown' }}</x-filament::badge></td>
                                    <td><x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\WebSalesOrders\WebSalesOrderResource::getUrl('view', ['record' => $order])">View</x-filament::button></td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $canSeeWebSalesValues ? 8 : 7 }}" class="py-8 text-center text-sm text-gray-500">No Web Sales Orders match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($webSales instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">{{ $webSales->links() }}</div>
                @endif
            </x-filament::section>
        @endif

        @if($activeTab === 'quotations' && isset($links['quotations']))
            <x-filament::section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">Quotations</h2>
                    <x-filament::button tag="a" :href="$links['quotations']" size="sm" color="gray">Open full module</x-filament::button>
                </div>
                <div class="overflow-x-auto">
                    <table class="tpz-workspace-table">
                        <thead><tr><th>Reference</th><th>Created</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            @forelse($quotations as $quotation)
                                <tr wire:key="workspace-quotation-{{ $quotation->getKey() }}">
                                    <td class="font-semibold">{{ $quotation->reference ?? '—' }}</td>
                                    <td>{{ $quotation->created_at?->format('d M Y') ?? '—' }}</td>
                                    <td>{{ str($quotation->status?->value ?? $quotation->status ?? 'Unknown')->headline() }}</td>
                                    <td>
                                        @if(\App\Filament\Resources\Quotations\QuotationResource::canView($quotation))
                                            <x-filament::button tag="a" size="xs" color="gray" :href="\App\Filament\Resources\Quotations\QuotationResource::getUrl('view', ['record' => $quotation])">View</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-gray-500">No quotations in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($quotations instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <div class="mt-4">{{ $quotations->links() }}</div>
                @endif
            </x-filament::section>
        @endif
        @if($activeTab === 'extra')
            @include('filament.pages.partials.workspace-extra-preview')
        @endif
    </div>
</x-filament-panels::page>
