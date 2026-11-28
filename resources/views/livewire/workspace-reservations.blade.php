<section class="tpz-native-list-panel tpz-stock-panel" aria-label="Reservation details">
    <div class="tpz-native-panel-title tpz-reservations-heading">
        <div><h2>Stock Reservations</h2><small>Authorized Sales reservations, order links and transfer holds. Employee allocations are never released here.</small></div>
        <div class="tpz-reservation-legend" aria-label="Reservation legend"><span>● Active</span><span>○ Released</span><span>✓ Fulfilled</span></div>
    </div>
    <form wire:submit="applyFilters" class="tpz-stock-filters"><label class="min-w-48 flex-1 text-xs">Search<x-filament::input.wrapper><x-filament::input placeholder="Order ID, reservation, SKU or product" wire:model="search" /></x-filament::input.wrapper></label>
        <label class="text-xs">Status<x-filament::input.wrapper><x-filament::input.select wire:model="status"><option value="">All statuses</option><option value="active">Active (Reserved)</option><option value="released">Released</option><option value="fulfilled">Fulfilled</option></x-filament::input.select></x-filament::input.wrapper></label>
        <label class="text-xs">Marketplace<x-filament::input.wrapper><x-filament::input.select wire:model="platform"><option value="">All</option>@foreach($platforms as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        <label class="text-xs">Location<x-filament::input.wrapper><x-filament::input.select wire:model="location"><option value="">All locations</option>@foreach($locations as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        <x-filament::button type="submit" size="sm">Apply Filters</x-filament::button><x-filament::button color="gray" size="sm" wire:click="resetFilters">Reset</x-filament::button>
    </form>
    <div class="overflow-x-auto"><table class="tpz-workspace-table tpz-reservation-table"><thead><tr><th>Reservation / Product</th><th>Source</th><th>Location</th><th>Qty</th><th>Related Order</th><th>Reserved On</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @forelse($rows as $row)
            <tr wire:key="reservation-{{ $row->id }}">
                @php($linkedOrder = $row->orderItem?->order)
                @php($canViewOrder = $linkedOrder && app(\App\Services\Authorization\OrderAuthorization::class)->allows(auth()->user(), \App\Enums\OrderPermission::View, $linkedOrder))
                <td>
                    <strong class="block text-blue-800 dark:text-blue-300">{{ $row->reference }}</strong>
                    <span class="mt-1 block whitespace-normal font-semibold">{{ $row->product?->name }}</span>
                    <small class="block text-gray-500">{{ $row->product?->sku }}</small>
                </td>
                <td><span class="{{ $linkedOrder ? 'tpz-reservation-source-sales' : 'tpz-reservation-source-hold' }}">{{ $linkedOrder ? 'Sales Order' : 'Transfer / Standalone hold' }}</span></td>
                <td>{{ $row->warehouse?->name ?? '—' }}</td>
                <td class="font-semibold tabular-nums">{{ number_format((int) $row->quantity) }}</td>
                <td>
                    @if($canViewOrder)
                        <a class="font-semibold text-blue-700 hover:underline dark:text-blue-300" href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record'=>$linkedOrder]) }}">{{ $linkedOrder->reference }}</a>
                    @elseif($linkedOrder)
                        <span class="text-gray-500">Restricted order</span>
                    @else
                        <span class="text-gray-500">No Sales Order</span>
                    @endif
                </td>
                <td><span class="block whitespace-nowrap">{{ app(\App\Services\UserTimezoneResolver::class)->format($row->reserved_at) }}</span><small class="block text-gray-500">{{ $row->reservedBy?->name ?? '—' }}</small></td>
                <td><span class="tpz-reservation-status">{{ $row->status->value === 'active' ? 'Reserved' : ucfirst($row->status->value) }}</span></td>
                <td>
                @if(\App\Filament\Resources\InventoryReservations\InventoryReservationResource::canView($row))<a class="tpz-stock-view" href="{{ \App\Filament\Resources\InventoryReservations\InventoryReservationResource::getUrl('view', ['record'=>$row]) }}">View</a>@endif
                @if($row->status->value === 'active' && $row->orderItem?->order && app(\App\Services\Orders\OrderReservationReleaseService::class)->allows(auth()->user(), $row->orderItem->order))<button type="button" class="tpz-stock-view tpz-stock-release" wire:click="prepareRelease({{ $row->id }})">Release</button>@endif
            </td></tr>
        @empty<tr><td colspan="8" class="py-6 text-center text-gray-500">No reservations in your authorized scope.</td></tr>@endforelse
    </tbody></table></div><div class="p-3">{{ $rows->links() }}</div>
    @if($pending)
        <div role="dialog" aria-modal="true" aria-label="Confirm reservation release" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div class="w-full max-w-2xl rounded-xl bg-white p-6 text-gray-950 shadow-xl dark:bg-gray-900 dark:text-white">
            <h2 class="text-lg font-bold">Release reservations for {{ $pending->orderItem->order->reference }}</h2>
            <p class="my-3 text-sm">All active base-product and upgrade reservations below will be released atomically. The order returns to Draft and requires reservation again. Physical stock and employee ownership do not change.</p>
            @foreach($bundle as $line)<p class="text-sm">{{ $line->product?->sku }} — {{ $line->warehouse?->name }} — {{ $line->quantity }} units</p>@endforeach
            <label class="mt-3 block">Required reason<textarea class="w-full rounded-lg border border-gray-300 bg-transparent p-2" wire:model="reason"></textarea></label>@error('reason')<p class="text-red-600">{{ $message }}</p>@enderror
            <div class="mt-4 flex gap-3"><x-filament::button color="warning" wire:click="confirmRelease" wire:loading.attr="disabled">Confirm full order release</x-filament::button><x-filament::button color="gray" wire:click="cancelRelease">Cancel</x-filament::button></div>
        </div></div>
    @endif
</section>
