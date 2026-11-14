<x-filament-panels::page>
    <x-filament::section heading="Sales Workspace" description="Existing Sales Orders, Web Sales, Quotations, and fulfilment in one permission-scoped entry point.">
        <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Order reference" aria-label="Search order reference" /></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="customer" placeholder="Web Sales customer" aria-label="Web Sales customer" /></x-filament::input.wrapper>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="status" aria-label="Order status">
                    <option value="all">All statuses</option>
                    @foreach (\App\Enums\OrderStatus::cases() as $orderStatus)
                        <option value="{{ $orderStatus->value }}">{{ $orderStatus->getLabel() }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" aria-label="From date" /></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" aria-label="To date" /></x-filament::input.wrapper>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="channel" aria-label="Web Sales channel">
                    <option value="">All Web Sales channels</option>
                    @foreach (\App\Enums\WebSalesChannel::cases() as $salesChannel)
                        <option value="{{ $salesChannel->value }}">{{ $salesChannel->getLabel() }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <div class="flex flex-wrap gap-2">
                @foreach (['orders' => 'Sales Orders', 'webSales' => 'Web Sales', 'quotations' => 'Quotations', 'dashboard' => 'Web Sales Dashboard'] as $key => $label)
                    @if (isset($links[$key]))
                        <x-filament::button tag="a" size="sm" :color="$key === 'orders' ? 'primary' : 'gray'" :href="$links[$key]">{{ $label }}</x-filament::button>
                    @endif
                @endforeach
            </div>
        </div>
    </x-filament::section>
    <nav aria-label="Sales workspace sections" class="my-4 flex flex-wrap gap-5 border-b border-gray-200 text-sm dark:border-white/10">
        <span aria-current="page" class="border-b-2 border-primary-600 px-1 pb-2 font-semibold text-primary-700 dark:text-primary-300">Overview</span>
        @foreach (['orders' => 'Sales Orders', 'webSales' => 'Web Sales', 'quotations' => 'Quotations'] as $key => $label)
            @if (isset($links[$key]))
                <a class="px-1 pb-2 text-gray-500 hover:text-primary-700 dark:text-gray-300" href="{{ $links[$key] }}">{{ $label }}</a>
            @endif
        @endforeach
    </nav>
    <div class="grid gap-3 sm:grid-cols-3"><x-filament::section compact><div class="text-xs text-gray-500">Sales Orders matching filters</div><div class="mt-1 text-2xl font-semibold">{{ $orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $orders->total() : $orders->count() }}</div></x-filament::section><x-filament::section compact><div class="text-xs text-gray-500">Web Sales matching filters</div><div class="mt-1 text-2xl font-semibold">{{ $webSales instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $webSales->total() : $webSales->count() }}</div></x-filament::section><x-filament::section compact><div class="text-xs text-gray-500">Next action</div><div class="mt-1 text-sm font-semibold">Open orders to manage fulfilment</div></x-filament::section></div>
    <div class="grid gap-5 xl:grid-cols-2"><x-filament::section compact heading="Sales Orders"><div class="divide-y divide-gray-100 dark:divide-white/5">@forelse ($orders as $order)<a class="flex items-center justify-between gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5" href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record' => $order]) }}"><span><span class="block font-semibold">{{ $order->reference }}</span><span class="text-xs text-gray-500">{{ $order->order_date?->format('d M Y') }} · {{ str($order->source?->value ?? 'unknown')->headline() }}</span></span><x-filament::badge>{{ $order->status?->getLabel() ?? $order->status?->value ?? 'Unknown' }}</x-filament::badge></a>@empty<p class="py-5 text-sm text-gray-500">No Sales Orders are visible in this scope.</p>@endforelse</div>@if ($orders instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<div class="mt-3">{{ $orders->links() }}</div>@endif</x-filament::section><x-filament::section compact heading="Web Sales Orders"><div class="divide-y divide-gray-100 dark:divide-white/5">@forelse ($webSales as $order)<a class="flex items-center justify-between gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5" href="{{ \App\Filament\Resources\WebSalesOrders\WebSalesOrderResource::getUrl('view', ['record' => $order]) }}"><span><span class="block font-semibold">{{ $order->reference }}</span><span class="text-xs text-gray-500">{{ $order->web_sales_channel?->value ?? 'Web Sales' }} · {{ $order->customer_name }}</span></span><x-filament::badge>{{ $order->status?->getLabel() ?? $order->status?->value ?? 'Unknown' }}</x-filament::badge></a>@empty<p class="py-5 text-sm text-gray-500">No Web Sales Orders are visible in this scope.</p>@endforelse</div>@if ($webSales instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)<div class="mt-3">{{ $webSales->links() }}</div>@endif</x-filament::section></div>
</x-filament-panels::page>
