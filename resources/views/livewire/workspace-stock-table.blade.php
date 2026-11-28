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
        <div role="dialog" aria-modal="true" aria-label="Stock breakdown" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div class="tpz-stock-details tpz-stock-dialog bg-white text-gray-950 dark:bg-gray-900 dark:text-white">
            <div class="tpz-stock-dialog-main">
                <div class="tpz-stock-dialog-header flex items-center justify-between gap-4">
                    <h2 class="tpz-stock-dialog-title"><x-filament::icon icon="heroicon-o-cube" class="size-5"/> Product Details</h2>
                    <x-filament::button color="gray" wire:click="closeDetails">Close</x-filament::button>
                </div>
                <div class="tpz-stock-hero tpz-ref-hero">
                    <div class="tpz-ref-identity">
                        <div class="tpz-ref-product-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-computer-desktop" class="size-14"/></div>
                        <div class="min-w-0">
                            <h3 class="tpz-stock-product-heading">{{ $details->product?->name }}</h3>
                            <div class="tpz-ref-meta"><span>SKU: <strong>{{ $details->product?->sku }}</strong></span><span>Brand: <strong>{{ $details->product?->brand ?? '—' }}</strong></span><span>Category: <strong>{{ $details->product?->category ?? '—' }}</strong></span><span class="tpz-ref-active">{{ ucfirst($details->product?->status?->value ?? 'Unknown') }}</span></div>
                            @if($brandAssignments->isNotEmpty())<p class="tpz-ref-responsible">Responsible: <strong>{{ $brandAssignments->first()?->employee?->name ?? '—' }}</strong></p>@endif
                        </div>
                    </div>
                    <div class="tpz-stock-product-summary tpz-ref-summary">
                        <div><small>Authorized Stock</small><strong>{{ number_format((int) $detailTotals['held']) }}</strong></div>
                        <div><small>Reserved</small><button class="tpz-ref-link" wire:click="detailSection('reservations')">{{ number_format((int) $detailTotals['reserved']) }}</button></div>
                        <div><small>Available</small><strong>{{ number_format((int) $detailTotals['sellable']) }}</strong></div>
                        @if($costHistory)<div class="tpz-ref-gold"><small>Latest Purchase Cost</small><strong>{{ $latestPurchaseData?->latestReceivedCost === null ? '—' : \App\Support\AedMoney::format($latestPurchaseData->latestReceivedCost) }}</strong></div>@endif
                        @if($financial)<div><small>Avg. Cost (Selected Location)</small><strong>{{ $detailCost === null ? '—' : \App\Support\AedMoney::format($detailCost) }}</strong></div><div><small>Inventory Value</small><strong>{{ \App\Support\AedMoney::format((string) $detailTotalValue) }}</strong></div>@endif
                    </div>
                </div>
            @if($historyData)
                <div class="mx-auto mt-3 max-w-4xl rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-label="Purchase Cost History">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div><h3 class="font-semibold">Purchase Cost History</h3><p class="text-xs text-gray-500">Latest received cost and posted Goods Receipts (GRNs).</p></div>
                        <button type="button" class="tpz-stock-view" wire:click="details({{ $details->id }})">Back to stock details</button>
                    </div>
                    @include('filament.purchases.purchase-cost-history', ['history'=>$historyData])
                </div>
            @else
                <nav class="tpz-stock-detail-tabs" aria-label="Product details sections">
                    @foreach(['allocation'=>'Inventory & Allocation','info'=>'Product Info','provenance'=>'Stock Movements & Provenance','reservations'=>'Orders & Reservations'] as $key=>$label)<button type="button" class="{{ $detailTab === $key ? 'is-active' : '' }}" wire:click="detailSection('{{ $key }}')">{{ $label }}</button>@endforeach
                    @if($costHistory)<button type="button" wire:click="purchaseHistory({{ $details->id }})">Purchase History</button>@endif
                </nav>
                @if($detailTab === 'info')
                    <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><dt class="text-xs text-gray-500">Brand</dt><dd class="mt-1 font-semibold">{{ $details->product?->brand ?? '—' }}</dd></div>
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><dt class="text-xs text-gray-500">Category</dt><dd class="mt-1 font-semibold">{{ $details->product?->category ?? '—' }}</dd></div>
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><dt class="text-xs text-gray-500">Model</dt><dd class="mt-1 font-semibold">{{ $details->product?->model ?? '—' }}</dd></div>
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><dt class="text-xs text-gray-500">Product status</dt><dd class="mt-1 font-semibold">{{ ucfirst($details->product?->status?->value ?? 'Unknown') }}</dd></div>
                    </dl>
                @endif
                @if($detailTab === 'allocation')
                    <div class="tpz-ref-grid">
                        <section class="tpz-stock-detail-card tpz-ref-locations">
                            <h3><x-filament::icon icon="heroicon-o-map-pin" class="size-5"/> Inventory by Location</h3>
                            <div class="overflow-x-auto"><table class="tpz-workspace-table tpz-ref-location-table">
                                <thead><tr><th>Location</th><th>On Hand</th><th>Reserved</th><th>Available</th>@if($financial)<th>Cost Price</th><th>Inventory Value</th>@endif<th>Action</th></tr></thead>
                                <tbody>@forelse($detailLocations as $place)
                                    <tr wire:key="ref-stock-location-{{ $place->id }}">
                                        <td class="font-semibold">{{ $place->warehouse?->name ?? 'Unknown Location' }}</td>
                                        <td>{{ number_format((int) $place->available_quantity) }}</td>
                                        <td><button class="tpz-ref-link" wire:click="details({{ $place->id }}, 'reservations')">{{ number_format((int) $place->reserved_quantity) }}</button></td>
                                        <td>{{ number_format(max(0, (int) $place->available_quantity - (int) $place->reserved_quantity)) }}</td>
                                        @if($financial)<td class="tpz-ref-cost-cell">{{ $detailRowCosts->get($place->id) === null ? '—' : \App\Support\AedMoney::format($detailRowCosts->get($place->id)) }}</td><td>{{ $detailRowCosts->get($place->id) === null ? '—' : \App\Support\AedMoney::format(bcmul((string) $detailRowCosts->get($place->id), (string) $place->available_quantity, 4)) }}</td>@endif
                                        <td><button class="tpz-stock-view" wire:click="details({{ $place->id }})"><x-filament::icon icon="heroicon-o-eye" class="size-4"/> Quick View</button></td>
                                    </tr>
                                @empty<tr><td colspan="7">No authorized locations.</td></tr>@endforelse</tbody>
                                <tfoot><tr><th>Total</th><th>{{ number_format((int) $detailTotals['held']) }}</th><th>{{ number_format((int) $detailTotals['reserved']) }}</th><th>{{ number_format((int) $detailTotals['sellable']) }}</th>@if($financial)<th>—</th><th>{{ \App\Support\AedMoney::format((string) $detailTotalValue) }}</th>@endif<th></th></tr></tfoot>
                            </table></div>
                        </section>
                        @if($costHistory)<section class="tpz-stock-detail-card tpz-ref-latest">
                            <div class="tpz-ref-card-header"><h3><x-filament::icon icon="heroicon-o-shopping-cart" class="size-5"/> Latest Purchase</h3><button class="tpz-stock-view" wire:click="purchaseHistory({{ $details->id }})">View Details</button></div>
                            @if($latestPurchaseData?->latestReceivedCost !== null)
                                <dl class="tpz-ref-purchase-data">
                                    <div><dt>Purchase Order</dt><dd>{{ $latestPurchaseData->recentEntries[0]['purchase_reference'] ?? '—' }}</dd></div>
                                    <div><dt>Goods Receipt (GRN)</dt><dd>{{ $latestPurchaseData->recentEntries[0]['grn_reference'] ?? '—' }}</dd></div>
                                    <div><dt>Purchase Date</dt><dd>{{ $latestPurchaseData->latestPurchaseDate?->format('d M Y') ?? '—' }}</dd></div>
                                    <div><dt>Receipt Date</dt><dd>{{ $latestPurchaseData->latestReceiptDate?->format('d M Y, H:i') ?? '—' }}</dd></div>
                                    <div><dt>Quantity Received</dt><dd>{{ number_format((int) $latestPurchaseData->latestReceivedQuantity) }}</dd></div>
                                    <div><dt>Unit Cost</dt><dd>{{ \App\Support\AedMoney::format($latestPurchaseData->latestReceivedCost) }}</dd></div>
                                </dl>
                            @else<p class="tpz-stock-note">No posted goods receipt cost history.</p>@endif
                        </section>@endif
                        @if($breakdown)<section class="tpz-stock-detail-card tpz-ref-ownership">
                            <h3><x-filament::icon icon="heroicon-o-building-office" class="size-5"/> Authorized Scope (Stock Ownership)</h3>
                            <p class="tpz-ref-location-line">{{ $details->warehouse?->name }} <strong>{{ number_format((int) $breakdown['total_stock']) }} units</strong></p>
                            @foreach($breakdown['lines'] as $line)<div class="tpz-stock-ownership-line"><span>{{ $line['label'] }}</span><span>{{ number_format((int) $line['allocated']) }} held · {{ number_format((int) $line['reserved']) }} reserved · {{ number_format((int) $line['sellable']) }} available</span></div>@endforeach
                            @if(!$breakdown['reconciled'])<p class="tpz-ref-warning">Ledger coverage difference: {{ $breakdown['allocation_gap'] }}; reservation difference: {{ $breakdown['reserved_gap'] }}. May include transfer holds. No balances changed.</p>@endif
                        </section>@endif
                        <section class="tpz-stock-detail-card tpz-ref-personal">
                            <h3><x-filament::icon icon="heroicon-o-user-circle" class="size-5"/> Current Employee Allocation</h3>
                            <div class="tpz-ref-personal-values"><div><small>Allocated</small><strong>{{ number_format((int) $detailOwn?->allocated_quantity) }}</strong></div><div><small>Reserved</small><strong>{{ number_format((int) $detailOwn?->reserved_quantity) }}</strong></div><div><small>Available</small><strong>{{ number_format(max(0, (int) $detailOwn?->allocated_quantity - (int) $detailOwn?->reserved_quantity)) }}</strong></div></div>
                            <p class="tpz-stock-note">Your personal allocation is included in the physical stock pool, not added to it.</p>
                        </section>
                        @if($brandAssignments->isNotEmpty())<section class="tpz-stock-detail-card tpz-ref-brand">
                            <h3><x-filament::icon icon="heroicon-o-user-group" class="size-5"/> Brand Responsibility</h3>
                            @foreach($brandAssignments as $assignment)<p class="tpz-ref-responsibility-row">Current Responsible <strong>{{ $assignment->employee?->name ?? '—' }}</strong></p>@endforeach
                            <p class="tpz-ref-info">Owner-reviewed reassignment of current operational scope requires a separate authorized stock handoff; historical orders, invoices and accounting records remain unchanged.</p>
                            <a class="tpz-ref-reassign" href="{{ \App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource::getUrl('index', ['tableSearch'=>$brandAssignments->first()->reference]) }}">Review Reassignment &amp; Current Stock ↗</a>
                        </section>@endif
                    </div>
                @endif
                @if($detailTab === 'reservations')
                <div class="tpz-ref-reservations-info"><strong>Reserved Stock</strong><span>Authorized Sales Order reservations are distinct from transfer holds. Only eligible Sales reservations have Release; allocations and transfer holds cannot be released here. Physical reserved stock may exceed visible Sales reservation lines.</span></div>
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
            </div>
        </div></div>
    @endif
</section>
