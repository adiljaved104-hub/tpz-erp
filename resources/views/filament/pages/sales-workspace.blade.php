<x-filament-panels::page>
    <div class="tpz-workspace">
        <header class="tpz-workspace-header flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="tpz-workspace-eyebrow">Customer &amp; marketplace operations</p>
                <h1 class="tpz-workspace-title">Sales Workspace</h1>
                <p class="tpz-workspace-subtitle">Orders, Web Sales and quotation work within your authorized scope.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if(isset($links['createOrder']))
                    <x-filament::button tag="a" :href="$links['createOrder']" icon="heroicon-o-plus">Create Sales Order</x-filament::button>
                @elseif(isset($links['orders']))
                    <x-filament::button tag="a" :href="$links['orders']" icon="heroicon-o-shopping-bag">Sales Orders</x-filament::button>
                @endif
                @if(isset($links['dashboard']))
                    <x-filament::button tag="a" color="gray" :href="$links['dashboard']" icon="heroicon-o-chart-bar">Web Sales Dashboard</x-filament::button>
                @endif
            </div>
        </header>

        <nav class="tpz-workspace-tabs" aria-label="Sales workspace sections">
            <span class="tpz-workspace-tab is-active" aria-current="page">Overview</span>
            @foreach (['orders' => 'Sales Orders', 'webSales' => 'Web Sales', 'quotations' => 'Quotations'] as $key => $label)
                @if(isset($links[$key]))
                    <a class="tpz-workspace-tab" href="{{ $links[$key] }}">{{ $label }}</a>
                @endif
            @endforeach
        </nav>

        <div class="tpz-workspace-metrics tpz-workspace-metrics--three">
            @if(isset($links['orders']))
                <div class="tpz-workspace-metric tpz-workspace-metric--blue">
                    <span class="tpz-workspace-metric-icon"><x-filament::icon icon="heroicon-o-shopping-bag" class="size-5" /></span>
                    <div>
                        <p class="tpz-workspace-metric-label">Sales Orders matching filters</p>
                        <p class="tpz-workspace-metric-value">{{ $orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? number_format($orders->total()) : $orders->count() }}</p>
                    </div>
                </div>
            @endif
            @if(isset($links['webSales']))
                <div class="tpz-workspace-metric tpz-workspace-metric--teal">
                    <span class="tpz-workspace-metric-icon"><x-filament::icon icon="heroicon-o-globe-alt" class="size-5" /></span>
                    <div>
                        <p class="tpz-workspace-metric-label">Web Sales matching filters</p>
                        <p class="tpz-workspace-metric-value">{{ $webSales instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? number_format($webSales->total()) : $webSales->count() }}</p>
                    </div>
                </div>
            @endif
            @if(isset($links['quotations']))
                <a href="{{ $links['quotations'] }}" class="tpz-workspace-metric tpz-workspace-metric--violet">
                    <span class="tpz-workspace-metric-icon"><x-filament::icon icon="heroicon-o-document-text" class="size-5" /></span>
                    <div>
                        <p class="tpz-workspace-metric-label">Quotations</p>
                        <p class="tpz-workspace-metric-link">Open quotations →</p>
                    </div>
                </a>
            @endif
        </div>

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

        @if(isset($links['orders']))
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

        @if(isset($links['webSales']))
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
    </div>
</x-filament-panels::page>
