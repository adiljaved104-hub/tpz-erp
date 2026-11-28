<section class="tpz-native-list-panel" aria-label="{{ $personal ? 'My Inventory' : 'Inventory by Location' }}">
    <div class="tpz-native-panel-title"><h2>{{ $personal ? 'My Inventory' : 'Inventory by Location' }}</h2><span class="text-xs text-gray-500">{{ $summary['skus'] }} SKUs · {{ $summary['rows'] }} location rows</span></div>
    <form wire:submit="applyFilters" class="flex flex-wrap items-end gap-3 p-4">
        <label class="min-w-48 flex-1 text-xs">Product / SKU<x-filament::input.wrapper><x-filament::input wire:model="search" placeholder="Search full title or SKU" /></x-filament::input.wrapper></label>
        <label class="text-xs">Location<x-filament::input.wrapper><x-filament::input.select wire:model="location"><option value="">All authorized locations</option>@foreach($locations as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @if($holders->isNotEmpty())<label class="text-xs">Employee<x-filament::input.wrapper><x-filament::input.select wire:model="holder"><option value="">All authorized holders</option>@foreach($holders as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>@endif
        <label class="text-xs">Stock<x-filament::input.wrapper><x-filament::input.select wire:model="stock"><option value="all">All stock</option><option value="in_stock">Sellable</option><option value="zero_stock">No sellable stock</option></x-filament::input.select></x-filament::input.wrapper></label>
        <x-filament::button type="submit">Apply Filters</x-filament::button><x-filament::button color="gray" wire:click="resetFilters">Reset</x-filament::button>
    </form>
    @error('location')<p class="px-4 text-red-600">{{ $message }}</p>@enderror
    <div class="grid grid-cols-2 gap-3 px-4 pb-4 md:grid-cols-4">
        @foreach(['held'=>($personal?'My allocated':'Physical available pool'),'reserved'=>'Reserved','sellable'=>'Sellable'] as $key=>$label)<div class="rounded-lg border border-blue-100 bg-blue-50 p-3 dark:border-blue-900 dark:bg-gray-900"><span class="text-xs text-gray-500">{{ $label }}</span><strong class="block text-xl">{{ number_format($summary[$key]) }}</strong></div>@endforeach
        <p class="text-xs text-gray-500">Reserved units are included in the available pool. Employee allocations subdivide stock; do not add them to physical totals.</p>
    </div>
    <div class="overflow-x-auto"><table class="tpz-workspace-table"><thead><tr><th class="min-w-64">Product / SKU</th><th>Location</th><th>{{ $personal ? 'My allocated' : 'Physical pool' }}</th><th>Allocated</th><th>Reserved</th><th>Sellable</th>@if($financial)<th>Average cost / value</th>@endif @if($costHistory)<th>Latest receipt cost</th>@endif<th>Details</th></tr></thead><tbody>
        @forelse($rows as $row)
            @php($held = (int) ($personal ? $own->get($row->id)?->allocated_quantity : $row->available_quantity))
            @php($reserved = (int) ($personal ? $own->get($row->id)?->reserved_quantity : $row->reserved_quantity))
            <tr wire:key="stock-{{ $row->id }}"><td><button type="button" wire:click="details({{ $row->id }})" class="block w-full whitespace-normal break-words text-left font-semibold text-blue-800 dark:text-blue-300">{{ $row->product?->name }}</button><small class="text-gray-500">{{ $row->product?->sku }}</small></td><td>{{ $row->warehouse?->name }}</td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $held }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $personal ? $held : (int)$row->allocated_quantity }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $reserved }}</button></td>
                <td><button wire:click="details({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $held-$reserved }}</button></td>
                @if($financial)<td>{{ $averageCosts->get($row->id) === null ? '—' : \App\Support\AedMoney::format($averageCosts->get($row->id)) }}<small class="block" title="Available-pool value; reserved units are included">{{ $averageCosts->get($row->id) === null ? '' : \App\Support\AedMoney::format(bcmul((string)$averageCosts->get($row->id),(string)$held,4)) }}</small></td>@endif
                @if($costHistory)<td><button wire:click="purchaseHistory({{ $row->id }})" class="text-blue-700 dark:text-blue-300">{{ $latest->get($row->id)?->latest_purchase_cost === null ? 'History' : 'AED '.number_format((float)$latest->get($row->id)->latest_purchase_cost,2) }}</button></td>@endif
                <td><x-filament::button color="gray" size="xs" wire:click="details({{ $row->id }})">Quick View</x-filament::button></td>
            </tr>
        @empty<tr><td colspan="9">No stock matches your authorized scope and filters.</td></tr>@endforelse
    </tbody></table></div><div class="p-3">{{ $rows->links() }}</div>
    @if($details)
        <div role="dialog" aria-modal="true" aria-label="Stock breakdown" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div class="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-xl bg-white p-6 text-gray-950 shadow-xl dark:bg-gray-900 dark:text-white">
            <div class="flex justify-between gap-4"><div><h2 class="whitespace-normal text-lg font-bold">{{ $details->product?->name }}</h2><small>{{ $details->product?->sku }} · {{ $details->warehouse?->name }}</small></div><x-filament::button color="gray" wire:click="closeDetails">Close</x-filament::button></div>
            @if($historyData)
                @include('filament.purchases.purchase-cost-history', ['history'=>$historyData])
            @else
                @if(\App\Filament\Resources\Products\ProductResource::canView($details->product))<a class="text-blue-700" href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('view',['record'=>$details->product]) }}">Open authorized product details</a>@endif
                @if($breakdown)<p class="my-3 text-sm">Physical available pool {{ $breakdown['total_stock'] }} − reserved {{ $breakdown['reserved'] }} = sellable {{ $breakdown['sellable'] }}. Allocated {{ $breakdown['allocated'] }} is included, not additional stock.</p>
                    @if(!$breakdown['reconciled'])<p class="text-sm text-amber-700">Ledger coverage difference: {{ $breakdown['allocation_gap'] }} units. Reservation difference: {{ $breakdown['reserved_gap'] }} (may include transfer holds). No balances are changed by this view.</p>@endif
                    @foreach($breakdown['lines'] as $line)<p class="text-sm">{{ $line['label'] }} — {{ $line['allocated'] }} held, {{ $line['reserved'] }} reserved, {{ $line['sellable'] }} available</p>@endforeach
                @endif
                <h3 class="mt-4 font-semibold">Reservations</h3>@forelse($reservationRows as $r)<p class="text-sm">{{ $r->reference }} · {{ $r->quantity }} · {{ $r->status->value }} · {{ $r->orderItem?->order ? (app(\App\Services\Authorization\OrderAuthorization::class)->allows(auth()->user(), \App\Enums\OrderPermission::View, $r->orderItem->order) ? $r->orderItem->order->reference : 'Restricted order') : 'Standalone / transfer hold' }}</p>@empty<p class="text-sm">No reservation lines visible in your scope.</p>@endforelse
                <h3 class="mt-4 font-semibold">Allocation provenance (read-only)</h3><p class="text-xs text-gray-500">Pooled ledger events, not serial-number tracking or a second spendable balance.</p>
                @forelse($events as $event)<p class="text-sm">#{{ $event->id }} {{ $event->event_type }} · {{ $event->fromAccount?->name ?? 'Receipt / external' }} → {{ $event->toAccount?->name ?? 'Consumed / dispatched' }} · {{ $event->quantity }} units</p>@empty<p class="text-sm">No allocation events visible in your scope.</p>@endforelse
                @foreach($provenanceReceipts as $receipt)<p class="text-sm">Physical transfer line #{{ $receipt->context_id }} · {{ $receipt->status }} · destination {{ $receipt->destinationInventory?->warehouse?->name ?? 'Awaiting receipt' }}. Pooled movement evidence; not a second spendable allocation.</p>@endforeach
                @livewire(\App\Livewire\WorkspaceReservations::class, ['inventoryId'=>$details->id], key('stock-reservations-'.$details->id))
            @endif
        </div></div>
    @endif
</section>
