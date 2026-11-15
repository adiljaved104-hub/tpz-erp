<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header tpz-workspace-header--plain flex flex-wrap items-start justify-between gap-4">
            <div>
                <nav class="tpz-native-breadcrumb" aria-label="Breadcrumb"><span>Sales</span><span aria-hidden="true">/</span><span aria-current="page">Sales Workspace</span></nav>
                <h1 class="tpz-workspace-title">Sales Workspace</h1>
                <p class="tpz-workspace-subtitle">Orders, Web Sales and quotation work within your authorized scope.</p>
            </div>
            <div class="tpz-workspace-context-actions flex flex-wrap gap-2" aria-label="Actions for selected sales section">
                @if($activeTab === 'extra' && $activeExtraModule !== null && isset($extraModules[$activeExtraModule]))
                    <x-filament::button tag="a" :href="$extraModules[$activeExtraModule]['url']" icon="heroicon-o-arrow-up-right">
                        Open {{ $extraModules[$activeExtraModule]['label'] }}
                    </x-filament::button>
                @elseif($activeTab === 'overview')
                    @if(isset($links['createOrder']))
                        <x-filament::button tag="a" :href="$links['createOrder']" icon="heroicon-o-plus">Create Sales Order</x-filament::button>
                    @endif
                    @if(isset($links['dashboard']))
                        <x-filament::button tag="a" color="gray" :href="$links['dashboard']" icon="heroicon-o-chart-bar">Web Sales Dashboard</x-filament::button>
                    @endif
                @elseif($activeTab === 'orders')
                    @if(isset($links['createOrder']))
                        <x-filament::button tag="a" :href="$links['createOrder']" icon="heroicon-o-plus">Create Sales Order</x-filament::button>
                    @endif
                    @if(isset($links['orders']))
                        <x-filament::button tag="a" color="gray" :href="$links['orders']" icon="heroicon-o-shopping-bag">All Sales Orders</x-filament::button>
                    @endif
                @elseif($activeTab === 'webSales')
                    @if(isset($links['createWebSale']))
                        <x-filament::button tag="a" :href="$links['createWebSale']" icon="heroicon-o-plus">Create Web Sale</x-filament::button>
                    @endif
                    @if(isset($links['dashboard']))
                        <x-filament::button tag="a" color="gray" :href="$links['dashboard']" icon="heroicon-o-chart-bar">Web Sales Dashboard</x-filament::button>
                    @elseif(isset($links['webSales']))
                        <x-filament::button tag="a" color="gray" :href="$links['webSales']" icon="heroicon-o-globe-alt">All Web Sales</x-filament::button>
                    @endif
                @elseif($activeTab === 'quotations')
                    @if(isset($links['createQuotation']))
                        <x-filament::button tag="a" :href="$links['createQuotation']" icon="heroicon-o-plus">Create Quotation</x-filament::button>
                    @endif
                    @if(isset($links['quotations']))
                        <x-filament::button tag="a" color="gray" :href="$links['quotations']" icon="heroicon-o-document-text">All Quotations</x-filament::button>
                    @endif
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Sales workspace sections">
            <button type="button" class="tpz-workspace-tab {{ $activeTab === 'overview' ? 'is-active' : '' }}" aria-selected="{{ $activeTab === 'overview' ? 'true' : 'false' }}" wire:click="setTab('overview')">Overview</button>
            @foreach (['orders' => 'Sales Orders', 'webSales' => 'Web Sales', 'quotations' => 'Quotations'] as $key => $label)
                @if(isset($links[$key]))
                    <button type="button" class="tpz-workspace-tab {{ $activeTab === $key ? 'is-active' : '' }}" aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')">{{ $label }}@if(isset($salesCounts[$key])) <span class="tpz-native-tab-count">{{ number_format($salesCounts[$key]) }}</span>@endif</button>
                @endif
            @endforeach
            @foreach($extraModules as $index => $module)
                <button type="button" wire:key="extra-module-tab-{{ $index }}"
                    class="tpz-workspace-tab {{ $activeTab === 'extra' && $activeExtraModule === $index ? 'is-active' : '' }}"
                    aria-selected="{{ $activeTab === 'extra' && $activeExtraModule === $index ? 'true' : 'false' }}"
                    wire:click="selectExtraModule({{ $index }})">{{ $module['label'] }}</button>
            @endforeach
        </nav>

        @if($activeTab === 'overview')
            <section class="tpz-unified-overview-panel">
                <div class="tpz-unified-section-title">
                    <h2>Operational Overview</h2>
                    <span>Live, permission-scoped totals</span>
                </div>
                <div class="tpz-native-overview-metrics">
                    @foreach ([
                        ['key' => 'orders', 'label' => 'Sales Orders', 'icon' => 'heroicon-o-shopping-bag', 'tone' => 'blue', 'url' => $links['orders'] ?? null],
                        ['key' => 'webSales', 'label' => 'Web Sales', 'icon' => 'heroicon-o-globe-alt', 'tone' => 'green', 'url' => $links['webSales'] ?? null],
                        ['key' => 'quotations', 'label' => 'Quotations', 'icon' => 'heroicon-o-document-text', 'tone' => 'amber', 'url' => $links['quotations'] ?? null],
                        ['key' => 'taxInvoices', 'label' => 'Tax Invoices', 'icon' => 'heroicon-o-receipt-percent', 'tone' => 'violet', 'url' => \\App\\Filament\\Resources\\TaxInvoices\\TaxInvoiceResource::canViewAny() ? \\App\\Filament\\Resources\\TaxInvoices\\TaxInvoiceResource::getUrl() : null],
                    ] as $metric)
                        @if(isset($salesCounts[$metric['key']]) && $metric['url'])
                            <a href="{{ $metric['url'] }}" class="tpz-native-overview-metric tpz-native-overview-metric--{{ $metric['tone'] }}">
                                <span class="tpz-native-overview-icon"><x-filament::icon :icon="$metric['icon']" class="size-5" /></span>
                                <span class="tpz-native-overview-label">{{ $metric['label'] }}</span>
                                <strong>{{ number_format($salesCounts[$metric['key']]) }}</strong>
                            </a>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if(in_array($activeTab, ['orders', 'webSales', 'quotations'], true))
            <div class="tpz-native-subtab-summary">
                <span class="tpz-native-overview-icon">
                    <x-filament::icon :icon="match($activeTab) {
                        'orders' => 'heroicon-o-shopping-bag',
                        'webSales' => 'heroicon-o-globe-alt',
                        default => 'heroicon-o-document-text',
                    }" class="size-5" />
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

        @if(in_array($activeTab, ['overview', 'orders', 'webSales'], true))
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

        @if(isset($links['orders']) && in_array($activeTab, ['overview', 'orders'], true))
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

        @if(isset($links['webSales']) && in_array($activeTab, ['overview', 'webSales'], true))
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
