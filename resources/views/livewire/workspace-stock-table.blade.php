<section class="tpz-native-list-panel tpz-stock-panel {{ $personal ? 'tpz-stock-personal' : '' }}" aria-label="{{ $personal ? 'My Inventory' : 'Inventory by Location' }}">
    <div class="tpz-native-panel-title"><div><h2>{{ $personal ? 'My Inventory' : 'Inventory by Location' }}</h2><small>{{ $personal ? 'Your allocated inventory, within your authorized responsibility.' : 'Authorized stock balances by warehouse and marketplace location.' }}</small></div></div>
    <form wire:submit="applyFilters" class="tpz-stock-filters">
        <label class="min-w-48 flex-1 text-xs">Product / SKU<x-filament::input.wrapper><x-filament::input wire:model="search" placeholder="Search full title or SKU" /></x-filament::input.wrapper></label>
        <label class="text-xs">Location<x-filament::input.wrapper><x-filament::input.select wire:model="location"><option value="">All authorized locations</option>@foreach($locations as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @if($holders->isNotEmpty())<label class="text-xs">Employee<x-filament::input.wrapper><x-filament::input.select wire:model="holder"><option value="">All authorized holders</option>@foreach($holders as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>@endif
        <label class="text-xs tpz-stock-filter-stock">Stock<x-filament::input.wrapper><x-filament::input.select wire:model="stock"><option value="all">All stock</option><option value="in_stock">Sellable</option><option value="zero_stock">No sellable stock</option></x-filament::input.select></x-filament::input.wrapper></label>
        <x-filament::button type="submit">Apply Filters</x-filament::button><x-filament::button color="gray" wire:click="resetFilters">Reset</x-filament::button>
    </form>
    @error('location')<p class="px-4 text-red-600">{{ $message }}</p>@enderror
    <div class="tpz-stock-kpis">
        @foreach(($personal ? ['rows'=>'Total Records','sellable'=>'Available','reserved'=>'Reserved','allocated'=>'Allocated','transit'=>'In Transit'] : ['skus'=>'SKUs','rows'=>'Location Rows','held'=>'Physical Pool','reserved'=>'Reserved','sellable'=>'Sellable']) as $key=>$label)
            <div><x-filament::icon :icon="\App\Support\SafeWorkspaceIcon::resolve(['rows'=>'heroicon-o-squares-2x2','skus'=>'heroicon-o-cube','held'=>'heroicon-o-archive-box','reserved'=>'heroicon-o-lock-closed','sellable'=>'heroicon-o-cube','allocated'=>'heroicon-o-users','transit'=>'heroicon-o-arrows-right-left'][$key])" class="size-6"/><span>{{ $label }}<strong>{{ number_format($key === 'transit' ? $inTransit : $summary[$key]) }}</strong></span></div>
        @endforeach
    </div>
    <p class="tpz-stock-note">Filtered totals · Records are SKU/location rows. Reserved is included in the pool; allocations subdivide stock, never add to it. In Transit is not available to sell.</p>
    <div class="overflow-x-auto"><table class="tpz-workspace-table tpz-stock-table"><thead><tr><th class="tpz-stock-product">Product Name / SKU</th><th>Location</th><th>{{ $personal ? 'My Stock' : 'Physical Pool' }}</th><th>Reserved</th><th>Allocated</th><th>Available</th>@if($financial)<th>Avg. Cost</th><th>Inventory Value</th>@endif @if($costHistory)<th>Latest Purchase Cost</th>@endif<th>Actions</th></tr></thead><tbody>
        @forelse($rows as $row)
            @php($held = (int) ($personal ? $own->get($row->id)?->allocated_quantity : $row->available_quantity))
            @php($reserved = (int) ($personal ? $own->get($row->id)?->reserved_quantity : $row->reserved_quantity))
            <tr wire:key="stock-{{ $row->id }}"><td><button type="button" wire:click="details({{ $row->id }})" class="block w-full whitespace-normal break-words text-left font-semibold text-blue-800 dark:text-blue-300">{{ $row->product?->name }}</button><small class="text-gray-500">{{ $row->product?->sku }}</small></td><td><button type="button" wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $row->warehouse?->name }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $held }}</button></td>
                <td><button wire:click="details({{ $row->id }}, 'reservations')" class="text-blue-700 dark:text-blue-300">{{ $reserved }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $personal ? $held : (int)$row->allocated_quantity }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $held-$reserved }}</button></td>
                @if($financial)<td>{{ $averageCosts->get($row->id) === null ? '—' : \App\Support\AedMoney::format($averageCosts->get($row->id)) }}</td><td title="Available-pool value; reserved units included">{{ $averageCosts->get($row->id) === null ? '—' : \App\Support\AedMoney::format(bcmul((string)$averageCosts->get($row->id),(string)$held,4)) }}</td>@endif
                @if($costHistory)<td><button wire:click="purchaseHistory({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $latest->get($row->id)?->latest_purchase_cost === null ? 'History' : 'AED '.number_format((float)$latest->get($row->id)->latest_purchase_cost,2) }}</button></td>@endif
                <td><button type="button" class="tpz-stock-view" wire:click="details({{ $row->id }})" aria-label="Quick View {{ $row->product?->sku }}"><x-filament::icon icon="heroicon-o-eye" class="size-4"/> Quick View</button></td>
            </tr>
        @empty<tr><td colspan="10">No stock matches your authorized scope and filters.</td></tr>@endforelse
    </tbody></table></div><div class="p-3">{{ $rows->links() }}</div>
    @if($details)
        <div role="dialog" aria-modal="true" aria-label="Stock breakdown" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div class="tpz-stock-details max-h-[90vh] w-full max-w-6xl overflow-y-auto rounded-xl bg-white p-5 text-gray-950 shadow-xl dark:bg-gray-900 dark:text-white">
            <h2 class="mb-3 text-lg font-bold">Product Details · Quick View</h2>
            <div class="flex justify-between gap-4"><div><h2 class="whitespace-normal text-lg font-bold">{{ $details->product?->name }}</h2><small>{{ $details->product?->sku }} · {{ $details->warehouse?->name }}</small></div><x-filament::button color="gray" wire:click="closeDetails">Close</x-filament::button></div>
            <div class="tpz-stock-product-summary">
                <div><small>Brand / Category</small><strong>{{ $details->product?->brand }} · {{ $details->product?->category }}</strong><small>{{ ucfirst($details->product?->status?->value ?? '') }}</small></div>
                <div><small>{{ $personal ? 'My allocated stock' : 'Authorized stock pool' }}</small><strong>{{ $personal ? (int) $detailOwn?->allocated_quantity : $details->available_quantity }}</strong></div>
                <div><small>Reserved</small><button class="text-blue-700 font-semibold" wire:click="detailSection('reservations')">{{ $personal ? (int) $detailOwn?->reserved_quantity : $details->reserved_quantity }}</button></div>
                @if($financial)<div><small>Average Cost</small><strong>{{ $detailCost === null ? 'No cost history' : \App\Support\AedMoney::format($detailCost) }}</strong></div>@endif
            </div>
            @if($historyData)
                @include('filament.purchases.purchase-cost-history', ['history'=>$historyData])
            @else
                @if(\App\Filament\Resources\Products\ProductResource::canView($details->product))<a class="text-blue-700" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('view',['record'=>$details->product]) }}">Open authorized product details</a>@endif
                <nav class="tpz-stock-detail-tabs" aria-label="Product details sections">
                    @foreach(['allocation'=>'Inventory & Allocation','info'=>'Product Info','provenance'=>'Stock Movements & Provenance','reservations'=>'Orders & Reservations'] as $key=>$label)<button type="button" class="{{ $detailTab === $key ? 'is-active' : '' }}" wire:click="detailSection('{{ $key }}')">{{ $label }}</button>@endforeach
                    @if($costHistory)<button type="button" wire:click="purchaseHistory({{ $details->id }})">Purchase History</button>@endif
                </nav>
                @if($detailTab === 'info')
                    <dl class="grid grid-cols-2 gap-4 py-4"><div><dt>Brand</dt><dd>{{ $details->product?->brand }}</dd></div><div><dt>Category</dt><dd>{{ $details->product?->category }}</dd></div><div><dt>Model</dt><dd>{{ $details->product?->model ?? '—' }}</dd></div><div><dt>Status</dt><dd>{{ $details->product?->status?->value }}</dd></div></dl>
                @endif
                @if($detailTab === 'allocation')
                <div class="tpz-stock-detail-columns"><div class="tpz-stock-detail-card">
                    <h3 class="mt-4 font-semibold">Inventory by Location</h3>
                    <table class="tpz-workspace-table"><thead><tr><th>Location</th><th>Authorized Pool</th><th>Reserved</th><th>Available</th><th>Actions</th></tr></thead><tbody>@foreach($detailLocations as $place)<tr><td>{{ $place->warehouse?->name }}</td><td>{{ $place->available_quantity }}</td><td><button class="text-blue-700" wire:click="details({{ $place->id }})">{{ $place->reserved_quantity }}</button></td><td>{{ $place->available_quantity-$place->reserved_quantity }}</td><td><button class="tpz-stock-view" wire:click="details({{ $place->id }})">Quick View</button></td></tr>@endforeach</tbody></table>
                @if($breakdown)<p class="my-3 text-sm">Physical available pool {{ $breakdown['total_stock'] }} − reserved {{ $breakdown['reserved'] }} = sellable {{ $breakdown['sellable'] }}. Allocated {{ $breakdown['allocated'] }} is included, not additional stock.</p>
                    @if(!$breakdown['reconciled'])<p class="text-sm text-amber-700">Ledger coverage difference: {{ $breakdown['allocation_gap'] }} units. Reservation difference: {{ $breakdown['reserved_gap'] }} (may include transfer holds). No balances are changed by this view.</p>@endif
                    @foreach($breakdown['lines'] as $line)<p class="text-sm">{{ $line['label'] }} — {{ $line['allocated'] }} held, {{ $line['reserved'] }} reserved, {{ $line['sellable'] }} available</p>@endforeach
                @endif
                </div><aside>
                    <div class="tpz-stock-detail-card"><h3 class="font-semibold">Current Employee Allocation</h3><p class="tpz-stock-note">{{ (int) $detailOwn?->allocated_quantity }} allocated · {{ (int) $detailOwn?->reserved_quantity }} reserved. These units are included in the stock pool, not additional inventory.</p></div>
                    @if($costHistory)<div class="tpz-stock-detail-card"><h3 class="font-semibold">Purchase Cost History</h3><p class="tpz-stock-note">Completed goods receipts; Purchase Date and Receipt Date remain separate.</p><button class="tpz-stock-view" wire:click="purchaseHistory({{ $details->id }})">View Purchase History</button></div>@endif
                    @if($brandAssignments->isNotEmpty())<div class="tpz-stock-detail-card"><h3 class="font-semibold">Brand / Product Responsibility</h3>@foreach($brandAssignments as $assignment)<p class="text-sm">{{ $assignment->employee?->name }} · {{ $assignment->reference }}</p>@endforeach<p class="tpz-stock-note">Each assignment retains its platform, warehouse and other scope restrictions. Owner-reviewed current stock handoff never rewrites historical documents.</p><a class="tpz-stock-view" href="{{ \App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource::getUrl('index', ['tableSearch'=>$brandAssignments->first()->reference]) }}">Review Reassignment &amp; Current Stock</a></div>@endif
                </aside></div>
                @endif
                @if($detailTab === 'reservations')
                <p class="tpz-stock-note">Only authorized Sales reservations can be released. Transfer holds and employee allocations are not released here; filtered lines may be a subset of the physical reserved balance.</p>
                <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div><h3 class="font-semibold">Stock Reservations</h3><p class="text-xs text-gray-500">One authorized list of Sales reservations and non-releasable transfer holds.</p></div>
                        <span class="rounded-md bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">Physical reserved: {{ number_format($details->reserved_quantity) }} units</span>
                    </div>
                    @livewire(\App\Livewire\WorkspaceReservations::class, ['inventoryId'=>$details->id], key('stock-reservations-'.$details->id))
                </div>
                @endif
                @if($detailTab === 'provenance')
                <div class="mt-4 mb-3">
                    <h3 class="font-semibold">Stock Movements &amp; Allocation Provenance</h3>
                    <p class="text-xs text-gray-500">Read-only pooled ledger history. Previous holders are shown for traceability only; historical events do not grant current stock ownership or create a second sellable balance.</p>
                </div>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="tpz-workspace-table w-full text-sm">
                        <thead><tr><th>Date</th><th>Movement</th><th>From → To</th><th>Qty</th><th>Reference</th></tr></thead>
                        <tbody>
                            @forelse($events as $event)
                                <tr wire:key="allocation-history-{{ $event->id }}">
                                    <td class="whitespace-nowrap">{{ $event->created_at?->format('d M Y, H:i') ?? '—' }}</td>
                                    <td class="whitespace-normal">{{ str_replace('_', ' ', ucfirst((string) $event->event_type)) }}</td>
                                    <td class="whitespace-normal">{{ $event->fromAccount?->name ?? 'Receipt / external' }} → {{ $event->toAccount?->name ?? 'Consumed / dispatched' }}</td>
                                    <td class="tabular-nums">{{ number_format((int) $event->quantity) }}</td>
                                    <td class="whitespace-nowrap">Event #{{ $event->id }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-gray-500">No allocation movements visible in your authorized scope.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($provenanceReceipts->isNotEmpty())
                    <h4 class="mt-5 mb-2 font-semibold">Physical warehouse transfers</h4>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                        <table class="tpz-workspace-table w-full text-sm">
                            <thead><tr><th>Transfer line</th><th>Destination</th><th>Receipt status</th></tr></thead>
                            <tbody>
                                @foreach($provenanceReceipts as $receipt)
                                    <tr wire:key="warehouse-receipt-{{ $receipt->id }}">
                                        <td>Line #{{ $receipt->context_id }}</td>
                                        <td>{{ $receipt->destinationInventory?->warehouse?->name ?? 'Awaiting destination confirmation' }}</td>
                                        <td>{{ str_replace('_', ' ', ucfirst((string) $receipt->status)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @endif
            @endif
        </div></div>
    @endif
</section>
