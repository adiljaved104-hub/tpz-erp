<section class="tpz-native-list-panel" aria-label="Reservation details">
    <div class="tpz-native-panel-title"><h2>Reservations</h2><p class="text-xs text-gray-500">Sales reservations and transfer holds are separate. No employee allocation is released here.</p></div>
    <div class="p-4"><x-filament::input.wrapper><x-filament::input placeholder="Search reservation, SKU or product" wire:model.live.debounce.400ms="search" /></x-filament::input.wrapper></div>
    <div class="overflow-x-auto"><table class="tpz-workspace-table"><thead><tr><th>Reference / Product</th><th>Location</th><th>Qty</th><th>Order</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        @forelse($rows as $row)
            <tr wire:key="reservation-{{ $row->id }}"><td><strong>{{ $row->reference }}</strong><span class="block whitespace-normal">{{ $row->product?->name }}</span><small>{{ $row->product?->sku }}</small><small class="block">{{ $row->reservedBy?->name }} · {{ app(\App\Services\UserTimezoneResolver::class)->format($row->reserved_at) }}</small></td><td>{{ $row->warehouse?->name }}</td><td>{{ $row->quantity }}</td><td>{{ $row->orderItem?->order ? (app(\App\Services\Authorization\OrderAuthorization::class)->allows(auth()->user(), \App\Enums\OrderPermission::View, $row->orderItem->order) ? $row->orderItem->order->reference : 'Restricted order') : 'Standalone / transfer hold' }}</td><td>{{ $row->status->value }}</td><td>
                @if(\App\Filament\Resources\InventoryReservations\InventoryReservationResource::canView($row))<a href="{{ \App\Filament\Resources\InventoryReservations\InventoryReservationResource::getUrl('view', ['record'=>$row]) }}">View</a>@endif
                @if($row->status->value === 'active' && $row->orderItem?->order && app(\App\Services\Orders\OrderReservationReleaseService::class)->allows(auth()->user(), $row->orderItem->order))<x-filament::button color="warning" size="xs" wire:click="prepareRelease({{ $row->id }})">Release</x-filament::button>@endif
            </td></tr>
        @empty<tr><td colspan="6">No reservations in your authorized scope.</td></tr>@endforelse
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
