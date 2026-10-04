<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $filters = $this->filterOptions();
    @endphp

    <x-filament::section heading="Stock available to request" description="Products in your Responsibility scope appear automatically. The holder column shows who controls the stock. Submitting a request does not move inventory.">
        <div class="mb-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <label><span class="mb-1 block text-sm font-medium">Search SKU, product, brand, warehouse or holder</span><x-filament::input.wrapper><x-filament::input wire:model.live.debounce.300ms="search" type="search" placeholder="Search stock" /></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="brand"><option value="">All brands</option>@foreach ($filters['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Warehouse</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse"><option value="">All warehouses</option>@foreach ($filters['warehouses'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Holder</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="holder"><option value="">All holders</option>@foreach ($filters['holders'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Rows per page</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option></x-filament::input.select></x-filament::input.wrapper></label>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-[640px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr><th class="px-3 py-2">Select</th><th class="px-3 py-2">Product / SKU</th><th class="px-3 py-2">Warehouse</th><th class="px-3 py-2">Request Qty</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            <tr wire:key="request-stock-{{ $row->id }}" class="align-top">
                                <td class="px-3 py-3"><input type="checkbox" wire:model.live="selected.{{ $row->id }}" aria-label="Select {{ $row->product->sku }}" class="rounded border-gray-300" /></td>
                                <td class="min-w-72 px-3 py-3"><div class="whitespace-normal break-words font-semibold">{{ $row->product->name }}</div><div class="text-xs text-gray-500">{{ $row->product->sku }} · {{ $row->product->brandRelation?->name ?? 'No brand' }}</div></td>
                                <td class="px-3 py-3">{{ $row->warehouse?->name }}</td>
                                <td class="px-3 py-3"><x-filament::input.wrapper><x-filament::input type="number" min="0" max="{{ $row->requestable }}" step="1" wire:model.blur="quantities.{{ $row->id }}" aria-label="Request quantity for {{ $row->product->sku }}" /></x-filament::input.wrapper>@error('quantities.'.$row->id)<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                            </tr>
                            <tr wire:key="request-stock-sources-{{ $row->id }}" data-stock-request-sources>
                                <td colspan="4" class="px-3 pb-4">
                                    <x-filament::section heading="Available Sources / Approval Status" compact>
                                        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            <div><dt class="text-xs text-gray-500">My Stock</dt><dd>{{ $row->my_stock }}</dd></div>
                                            <div><dt class="text-xs text-gray-500">Reserved</dt><dd>{{ $row->reserved_quantity }}</dd></div>
                                            <div><dt class="text-xs text-gray-500">Available From Others</dt><dd>{{ $row->available_from_others }}</dd></div>
                                            <div><dt class="text-xs text-gray-500">Stock Holder / Handled By</dt><dd>@forelse ($row->holder_labels as $holderLabel)<div>{{ $holderLabel }}</div>@empty No allocation holder @endforelse</dd></div>
                                            <div><dt class="text-xs text-gray-500">Request Status</dt><dd>@if ($row->request_status)<a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\StockRequests\StockRequestResource::getUrl('view', ['record' => $row->request_status->request_id]) }}">{{ $row->request_status->reference }} · {{ str_replace('_', ' ', $row->request_status->status) }}</a>@else No request @endif</dd></div>
                                            <div><dt class="text-xs text-gray-500">Approval Route</dt><dd>Allocation Holder; System / Unassigned requires Owner/Admin</dd></div>
                                        </dl>
                                    </x-filament::section>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No stock rows match your Responsibility scope and filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $rows->onEachSide(1)->links() }}</div>
            <label class="block"><span class="mb-1 block text-sm font-medium">Reason for request</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="reason" maxlength="2000" placeholder="Why is this stock needed?" /></x-filament::input.wrapper>@error('reason')<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</label>
            @error('quantities')<div class="text-sm text-danger-600">{{ $message }}</div>@enderror
            <div class="flex justify-end"><x-filament::button type="submit">Submit Stock Request</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
