<x-filament-panels::page>
    <style>
        .stock-request-columns-option input { accent-color: rgb(249 115 22); }
        .stock-request-columns-option { min-height: 2.25rem; }
    </style>
    @php
        $rows = $this->rows();
        $filters = $this->filterOptions();
    @endphp

    <x-filament::section heading="Stock available to request" description="Browse authorized stock and request quantities. Requests never move inventory until approved and executed.">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="min-w-0 flex-1"><span class="sr-only">Search stock</span><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input wire:model.live.debounce.300ms="search" type="search" placeholder="Search SKU, product, brand, warehouse or holder" /></x-filament::input.wrapper></label>
            <x-filament::dropdown placement="bottom-start" teleport>
                <x-slot name="trigger"><span class="relative inline-flex"><x-filament::icon-button color="gray" icon="heroicon-m-funnel" label="Filters" tooltip="Filters" /> @if ($this->activeFilterCount() > 0)<x-filament::badge size="sm" class="absolute -end-1 -top-2">{{ $this->activeFilterCount() }}</x-filament::badge>@endif</span></x-slot>
                <div class="w-80 space-y-3 rounded-xl p-4 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center justify-between border-b border-gray-200 pb-3 dark:border-white/10"><h3 class="text-sm font-bold text-gray-950 dark:text-white">Filters</h3><button type="button" class="text-sm font-medium text-danger-600 hover:text-danger-700" wire:click="resetFilters">Reset</button></div>
                    <label class="block text-sm">Brand<x-filament::input.select wire:model="brand"><option value="">All brands</option>@foreach ($filters['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></label>
                    <label class="block text-sm">Warehouse<x-filament::input.select wire:model="warehouse"><option value="">All warehouses</option>@foreach ($filters['warehouses'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></label>
                    <label class="block text-sm">Stock Holder<x-filament::input.select wire:model="holder"><option value="">All holders</option>@foreach ($filters['holders'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></label>
                    <label class="block text-sm">Stock Availability<x-filament::input.select wire:model="stockFilter"><option value="">All stock</option><option value="saleable">Saleable &gt; 0</option><option value="reserved">Reserved &gt; 0</option><option value="damaged">Damaged &gt; 0</option><option value="zero">Zero saleable</option></x-filament::input.select></label>
                    <label class="block text-sm">Sort By<x-filament::input.select wire:model="sortBy"><option value="sku">SKU / Product</option><option value="low_stock">Lowest saleable</option><option value="highest_stock">Highest saleable</option><option value="recent">Recently updated</option></x-filament::input.select></label>
                    <div class="flex justify-end border-t border-gray-200 pt-3 dark:border-white/10"><x-filament::button size="sm" color="warning" wire:click="applyFilters">Apply Filters</x-filament::button></div>
                </div>
            </x-filament::dropdown>
            <x-filament::dropdown placement="bottom-end" teleport>
                <x-slot name="trigger"><span class="inline-flex"><x-filament::icon-button color="gray" icon="heroicon-m-view-columns" label="Columns" tooltip="Columns" /><span class="sr-only">Show / Hide Columns</span></span></x-slot>
                <div class="w-72 rounded-xl p-4 shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"><div class="mb-3 flex items-center justify-between border-b border-gray-200 pb-3 dark:border-white/10"><h3 class="text-sm font-bold text-gray-950 dark:text-white">Columns</h3><button type="button" class="text-sm font-medium text-danger-600 hover:text-danger-700" wire:click="resetColumns">Reset</button></div><div class="space-y-1">
                    @foreach (['warehouse' => 'Warehouse', 'mine' => 'My Stock', 'reserved' => 'Reserved', 'others' => 'Available From Others', 'holder' => 'Stock Holders', 'request_status' => 'Request Status'] as $key => $label)
                        <x-filament::dropdown.list.item wire:click="toggleColumn('{{ $key }}')" class="stock-request-columns-option">
                            <span class="flex items-center gap-2">
                                <input type="checkbox" tabindex="-1" aria-hidden="true" class="rounded border-gray-300" @checked($this->columnVisible($key)) readonly />
                                <span>{{ $label }}</span>
                            </span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </div><div class="mt-3 flex justify-end border-t border-gray-200 pt-3 dark:border-white/10"><x-filament::button size="sm" color="warning" wire:click="applyColumns">Apply Columns</x-filament::button></div></div>
            </x-filament::dropdown>
            <label class="w-28"><span class="mb-1 block text-xs font-medium">Rows</span><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option></x-filament::input.select></label>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-[900px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="w-12 px-3 py-2">Select</th>
                            <th class="min-w-72 px-3 py-2">Product / SKU</th>
                            @if ($this->columnVisible('warehouse'))<th class="min-w-32 px-3 py-2">Warehouse</th>@endif
                            @if ($this->columnVisible('mine'))<th class="px-3 py-2">My Stock</th>@endif
                            @if ($this->columnVisible('reserved'))<th class="px-3 py-2">Reserved</th>@endif
                            @if ($this->columnVisible('others'))<th class="px-3 py-2">From Others</th>@endif
                            @if ($this->columnVisible('holder'))<th class="min-w-44 px-3 py-2">Stock Holders</th>@endif
                            @if ($this->columnVisible('request_status'))<th class="min-w-36 px-3 py-2">Request Status</th>@endif
                            <th class="min-w-32 px-3 py-2">Request Qty</th>
                            <th class="w-28 px-3 py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            <tr wire:key="request-stock-{{ $row->id }}" class="align-top">
                                <td class="px-3 py-3"><input type="checkbox" wire:model.live="selected.{{ $row->id }}" aria-label="Select {{ $row->product->sku }}" class="rounded border-gray-300" /></td>
                                <td class="min-w-72 px-3 py-3"><div class="whitespace-normal break-words font-semibold">{{ $row->product->name }}</div><div class="text-xs text-gray-500">{{ $row->product->sku }} · {{ $row->product->brandRelation?->name ?? 'No brand' }}</div></td>
                                @if ($this->columnVisible('warehouse'))<td class="px-3 py-3">{{ $row->warehouse?->name }}</td>@endif
                                @if ($this->columnVisible('mine'))<td class="px-3 py-3 font-medium">{{ $row->my_stock }}</td>@endif
                                @if ($this->columnVisible('reserved'))<td class="px-3 py-3">{{ $row->reserved_quantity }}</td>@endif
                                @if ($this->columnVisible('others'))<td class="px-3 py-3">{{ $row->available_from_others }}</td>@endif
                                @if ($this->columnVisible('holder'))<td class="px-3 py-3 text-xs">@forelse ($row->holder_labels as $holderLabel)<div>{{ $holderLabel }}</div>@empty<span class="text-gray-500">No allocation holder</span>@endforelse</td>@endif
                                @if ($this->columnVisible('request_status'))<td class="px-3 py-3 text-xs">@if ($row->request_status)<a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\StockRequests\StockRequestResource::getUrl('view', ['record' => $row->request_status->request_id]) }}">{{ $row->request_status->reference }} · {{ str_replace('_', ' ', $row->request_status->status) }}</a>@else<span class="text-gray-500">No request</span>@endif</td>@endif
                                <td class="px-3 py-3"><x-filament::input.wrapper><x-filament::input type="number" min="0" max="{{ $row->requestable }}" step="1" wire:model.blur="quantities.{{ $row->id }}" aria-label="Request quantity for {{ $row->product->sku }}" /></x-filament::input.wrapper>@error('quantities.'.$row->id)<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                                <td class="px-3 py-3"><x-filament::button size="sm" color="warning" wire:click="openRowRequest({{ $row->id }})" wire:loading.attr="disabled" wire:target="openRowRequest({{ $row->id }})" :disabled="$row->requestable < 1">Request</x-filament::button></td>
                            </tr>
                            <tr wire:key="request-stock-sources-{{ $row->id }}" data-stock-request-sources>
                                <td colspan="{{ $this->visibleColumnCount() }}" class="px-3 pb-3 pt-0">
                                    <details class="rounded-lg border border-gray-200 bg-gray-50/70 dark:border-white/10 dark:bg-white/5">
                                        <summary class="cursor-pointer px-3 py-2 text-xs font-medium">Available Sources / Approval Status · Expand details</summary>
                                        <dl class="grid gap-3 border-t border-gray-200 px-3 py-3 text-xs dark:border-white/10 sm:grid-cols-2 lg:grid-cols-3">
                                            <div><dt class="text-gray-500">My Stock</dt><dd>{{ $row->my_stock }}</dd></div>
                                            <div><dt class="text-gray-500">Reserved</dt><dd>{{ $row->reserved_quantity }}</dd></div>
                                            <div><dt class="text-gray-500">Available From Others</dt><dd>{{ $row->available_from_others }}</dd></div>
                                            <div><dt class="text-gray-500">Stock Holder / Handled By</dt><dd>@forelse ($row->holder_labels as $holderLabel)<div>{{ $holderLabel }}</div>@empty No allocation holder @endforelse</dd></div>
                                            <div><dt class="text-gray-500">Request Status</dt><dd>@if ($row->request_status)<a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\StockRequests\StockRequestResource::getUrl('view', ['record' => $row->request_status->request_id]) }}">{{ $row->request_status->reference }} · {{ str_replace('_', ' ', $row->request_status->status) }}</a>@else No request @endif</dd></div>
                                            <div><dt class="text-gray-500">Approval Route</dt><dd>Allocation Holder; System / Unassigned requires Owner/Admin</dd></div>
                                        </dl>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $this->visibleColumnCount() }}" class="px-4 py-8 text-center text-gray-500">No stock rows match your Responsibility scope and filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $rows->onEachSide(1)->links() }}</div>
            <label class="block"><span class="mb-1 block text-sm font-medium">Reason for request</span><x-filament::input.wrapper><x-filament::input type="text" wire:model="reason" maxlength="2000" placeholder="Why is this stock needed?" /></x-filament::input.wrapper>@error('reason')<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</label>
            @error('quantities')<div class="text-sm text-danger-600">{{ $message }}</div>@enderror
            <div class="flex justify-end"><x-filament::button type="submit" wire:loading.attr="disabled" wire:target="submit">Submit Stock Request</x-filament::button></div>
        </form>
    </x-filament::section>

    @if ($rowRequestInventoryId)
        @php($rowInventory = collect($rows->items())->firstWhere('id', $rowRequestInventoryId))
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4" role="dialog" aria-modal="true" aria-label="Request stock">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-2xl dark:bg-gray-900">
                <div class="flex items-center justify-between"><h2 class="text-lg font-semibold">Request Stock</h2><button type="button" class="text-gray-500" wire:click="closeRowRequest" aria-label="Close">×</button></div>
                @if ($rowInventory)
                    <p class="mt-3 text-sm"><span class="font-semibold">{{ $rowInventory->product->sku }}</span> · {{ $rowInventory->product->name }}</p>
                    <p class="mt-1 text-xs text-gray-500">Available from authorized sources: {{ $rowInventory->requestable }}</p>
                @endif
                <div class="mt-4 space-y-3">
                    <label class="block text-sm font-medium">Quantity<x-filament::input.wrapper><x-filament::input type="number" min="1" max="{{ $rowInventory?->requestable ?? 1 }}" wire:model="rowRequestQuantity" /></x-filament::input.wrapper></label>
                    <label class="block text-sm font-medium">Reason<x-filament::input.wrapper><x-filament::input type="text" maxlength="2000" wire:model="rowRequestReason" placeholder="Why is this stock needed?" /></x-filament::input.wrapper></label>
                    @error('rowRequestQuantity')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    @error('rowRequestReason')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </div>
                <div class="mt-5 flex justify-end gap-2"><x-filament::button color="gray" wire:click="closeRowRequest">Cancel</x-filament::button><x-filament::button color="warning" wire:click="submitRowRequest" wire:loading.attr="disabled" wire:target="submitRowRequest">Submit Request</x-filament::button></div>
            </div>
        </div>
    @endif
    @if ($rowRequestResultUrl)
        <div class="mt-3 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-950/30 dark:text-success-300">Request submitted. <a class="font-semibold underline" href="{{ $rowRequestResultUrl }}">View Stock Request</a></div>
    @endif
</x-filament-panels::page>
