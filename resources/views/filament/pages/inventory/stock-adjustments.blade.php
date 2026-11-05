<x-filament-panels::page>
    <style>
        .stock-adjustments-grid th {
            width: var(--column-width);
            min-width: var(--column-width);
            white-space: normal;
        }

        .stock-adjustments-grid .stock-adjustments-product {
            width: 244px;
            overflow-wrap: anywhere;
        }

        .stock-adjustments-grid .fi-badge,
        .stock-adjustments-grid .fi-badge-label {
            max-width: 100%;
            overflow: visible;
            white-space: normal;
            overflow-wrap: anywhere;
        }
    </style>
    @php
        $rows = $this->rows();
        $filters = $this->filterOptions();
        $mayAdjust = $this->canAdjust();
    @endphp

    <x-filament::section heading="Prepare Stock Adjustments" description="Every visible stock row is ready to review. Changes stay in this grid until Save All. Reserved stock is read-only.">
        @if ($linkedReceiptItemId)
            <div class="mb-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-900 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-100">GRN item #{{ $linkedReceiptItemId }} is linked to the highlighted stock row. The GRN itself remains unchanged.</div>
        @endif
        <div class="mb-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <label><span class="mb-1 block text-sm font-medium">Search SKU / Product</span><x-filament::input.wrapper><x-filament::input wire:model.live.debounce.300ms="search" type="search" placeholder="Search stock" /></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="brand"><option value="">All brands</option>@foreach ($filters['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Warehouse</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouse"><option value="">All warehouses</option>@foreach ($filters['warehouses'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Stock Holder</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="holder"><option value="">All holders</option>@foreach ($filters['holders'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Stock</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="stockFilter"><option value="">All stock</option><option value="saleable">Saleable &gt; 0</option><option value="damaged">Damaged &gt; 0</option><option value="reserved">Reserved &gt; 0</option><option value="zero">Zero saleable</option></x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Rows per page</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option></x-filament::input.select></x-filament::input.wrapper></label>
        </div>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50/80 px-3 py-3 dark:border-white/10 dark:bg-white/5">
            <div class="flex flex-wrap items-end gap-3">
                <label><span class="mb-1 block text-xs font-medium">Sort by</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="sortBy"><option value="sku">SKU / Product</option><option value="low_stock">Lowest saleable</option><option value="highest_stock">Highest saleable</option><option value="recent">Recently updated</option></x-filament::input.select></x-filament::input.wrapper></label>
                <button type="button" wire:click="resetFilters" class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-white dark:border-white/20 dark:hover:bg-white/10">Clear filters</button>
            </div>
            <details class="relative text-sm">
                <summary class="cursor-pointer rounded-lg border border-gray-300 px-3 py-2 font-medium dark:border-white/20">Show / Hide Columns</summary>
                <div class="absolute right-0 z-20 mt-2 grid w-56 gap-2 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-white/20 dark:bg-gray-900">
                    @foreach (['brand' => 'Brand', 'warehouse' => 'Warehouse', 'saleable' => 'Saleable', 'damaged' => 'Damaged', 'reserved' => 'Reserved', 'holder' => 'Stock Holders', 'new_purchase' => 'New Purchase?', 'status' => 'Status'] as $key => $label)
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="visibleColumns" value="{{ $key }}" class="rounded border-gray-300" />{{ $label }}</label>
                    @endforeach
                    <p class="text-xs text-gray-500">Product, selection and adjustment inputs stay visible for safe posting.</p>
                </div>
            </details>
        </div>

        <form wire:submit="saveAll" class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-gray-500">Edits remain in this grid until saved. Filtering or changing pages does not submit stock.</p>
                <x-filament::button type="submit" :disabled="! $mayAdjust" wire:loading.attr="disabled" wire:target="saveAll">Save All</x-filament::button>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="stock-adjustments-grid w-full divide-y divide-gray-200 text-sm dark:divide-white/10" style="min-width: 1100px; table-layout: auto;">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                        <tr>
                            <th class="px-2 py-2" style="--column-width: 60px;">Select</th>
                            <th class="px-2 py-2" style="--column-width: 260px;">Product / SKU</th>
                            @if ($this->columnVisible('brand'))<th class="px-2 py-2" style="--column-width: 100px;">Brand</th>@endif
                            @if ($this->columnVisible('warehouse'))<th class="px-2 py-2" style="--column-width: 120px;">Warehouse</th>@endif
                            @if ($this->columnVisible('saleable'))<th class="px-2 py-2" style="--column-width: 75px;">Saleable</th>@endif
                            @if ($this->columnVisible('damaged'))<th class="px-2 py-2" style="--column-width: 75px;">Damaged</th>@endif
                            @if ($this->columnVisible('reserved'))<th class="px-2 py-2" style="--column-width: 75px;">Reserved</th>@endif
                            @if ($this->columnVisible('holder'))<th class="px-2 py-2" style="--column-width: 150px;">Stock Holder</th>@endif
                            <th class="px-2 py-2" style="--column-width: 220px;">Adjustment Type</th>
                            <th class="px-2 py-2" style="--column-width: 100px;">Qty</th>
                            @if ($this->columnVisible('new_purchase'))<th class="px-2 py-2" style="--column-width: 120px;">New Purchase?</th>@endif
                            <th class="px-2 py-2" style="--column-width: 240px;">Reason</th>
                            @if ($this->columnVisible('status'))<th class="px-2 py-2" style="--column-width: 150px;">Status</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($rows as $row)
                            @php
                                $draft = $drafts[$row->id] ?? [];
                                $status = $this->rowStatus($row);
                                $increase = ($draft['type'] ?? null) === 'saleable_increase';
                                $linked = $linkedInventoryId === $row->id;
                            @endphp
                            <tr wire:key="adjust-stock-{{ $row->id }}" class="align-top {{ $linked ? 'bg-primary-50 dark:bg-primary-500/10' : '' }}">
                                <td class="px-2 py-3"><input type="checkbox" wire:model.live="drafts.{{ $row->id }}.selected" @disabled(! $mayAdjust) aria-label="Select {{ $row->product->sku }}" class="rounded border-gray-300" /></td>
                                <td class="px-2 py-3"><div class="stock-adjustments-product"><div class="font-semibold">{{ $row->product->name }}</div><div class="text-xs text-gray-500">{{ $row->product->sku }}</div>@if ($linked)<div class="text-xs font-semibold text-primary-600">Linked GRN</div>@endif</div></td>
                                @if ($this->columnVisible('brand'))
                                <td class="px-2 py-3">{{ $row->product->brandRelation?->name ?? '—' }}</td>
                                @endif
                                @if ($this->columnVisible('warehouse'))
                                <td class="px-2 py-3">{{ $row->warehouse?->name }}</td>
                                @endif
                                @if ($this->columnVisible('saleable'))
                                <td class="px-2 py-3 font-semibold">{{ $row->available_quantity }}</td>
                                @endif
                                @if ($this->columnVisible('damaged'))
                                <td class="px-2 py-3">{{ $row->damaged_quantity }}</td>
                                @endif
                                @if ($this->columnVisible('reserved'))
                                <td class="px-2 py-3">{{ $row->reserved_quantity }}</td>
                                @endif
                                @if ($this->columnVisible('holder'))
                                <td class="px-2 py-3 text-xs">
                                    @if ($this->shouldSelectAdjustmentHolder($row, $draft['type'] ?? null))
                                        <label class="mb-1 block text-xs font-medium">Original stock holder</label>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model.live="drafts.{{ $row->id }}.allocation_account_id" :disabled="! $mayAdjust">
                                                <option value="">Select holder</option>
                                                @foreach ($this->stockAdjustmentHolderOptions($row, $draft['type'] ?? null) as $accountId => $name)
                                                    <option value="{{ $accountId }}">{{ $name }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                        @if ($this->requiresScopedHolderTarget($row) && $this->stockAdjustmentHolderOptions($row, $draft['type'] ?? null) === [])
                                            <span class="mt-1 block text-danger-600">Holder access must be enabled on this warehouse grant.</span>
                                        @endif
                                    @else
                                        @forelse ($row->holder_labels as $holderLabel)<span class="mr-1 inline-block">{{ $holderLabel }}</span>@empty<span class="text-gray-500">No allocation holder</span>@endforelse
                                    @endif
                                </td>
                                @endif
                                <td class="px-2 py-3"><x-filament::input.wrapper><x-filament::input.select wire:model.live="drafts.{{ $row->id }}.type" :disabled="! $mayAdjust"><option value="">No change</option><option value="saleable_increase">Saleable +</option><option value="saleable_decrease">Saleable −</option><option value="mark_damaged">Move to Damaged</option><option value="restore_damaged">Restore from Damaged</option></x-filament::input.select></x-filament::input.wrapper></td>
                                <td class="px-2 py-3"><x-filament::input.wrapper><x-filament::input type="number" min="1" step="1" wire:model.blur="drafts.{{ $row->id }}.quantity" :disabled="! $mayAdjust" aria-label="Quantity for {{ $row->product->sku }}" /></x-filament::input.wrapper>@error('drafts.'.$row->id.'.quantity')<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                                @if ($this->columnVisible('new_purchase'))
                                <td class="px-2 py-3">@if ($increase)<x-filament::input.wrapper><x-filament::input.select wire:model.live="drafts.{{ $row->id }}.is_new_purchase" :disabled="! $mayAdjust"><option value="">Choose</option><option value="yes">Yes</option><option value="no">No</option></x-filament::input.select></x-filament::input.wrapper>@if (($draft['is_new_purchase'] ?? null) === 'yes' && $this->canQuickPurchase())<a target="_blank" rel="noopener" class="mt-2 block text-xs font-semibold text-primary-600 hover:underline" href="{{ \App\Filament\Pages\Purchasing\QuickStockPurchase::getUrl(['product_id' => $row->product_id, 'warehouse_id' => $row->warehouse_id]) }}">Open Quick Stock Purchase</a>@endif @else<span class="text-gray-500">—</span>@endif</td>
                                @endif
                                <td class="min-w-56 px-2 py-3"><x-filament::input.wrapper><x-filament::input type="text" maxlength="2000" wire:model.blur="drafts.{{ $row->id }}.reason" :disabled="! $mayAdjust" placeholder="Physical count reason" /></x-filament::input.wrapper>@if ($increase && ($draft['is_new_purchase'] ?? null) === 'no' && ! $linked && app(\App\Services\Authorization\InventoryAuthorization::class)->allows(auth()->user(), \App\Enums\InventoryPermission::ViewFinancials))<div class="mt-2"><label class="text-xs">Valuation cost if balance is empty (AED)</label><x-filament::input.wrapper><x-filament::input type="text" wire:model.blur="drafts.{{ $row->id }}.valuation_unit_cost" /></x-filament::input.wrapper></div>@endif</td>
                                @if ($this->columnVisible('status'))
                                <td class="px-2 py-3"><x-filament::badge :color="match ($status) { 'Ready', 'Saved' => 'success', 'New Purchase' => 'info', 'No Change' => 'gray', 'Reserved Stock Involved' => 'warning', default => 'danger' }">{{ $status }}</x-filament::badge>@error('drafts.'.$row->id)<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="13" class="px-4 py-8 text-center text-gray-500">No stock rows match your Responsibility scope and filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $rows->onEachSide(1)->links() }}</div>
            @error('drafts')<div class="text-sm text-danger-600">{{ $message }}</div>@enderror
            <div class="flex justify-end"><x-filament::button type="submit" :disabled="! $mayAdjust" wire:loading.attr="disabled" wire:target="saveAll">Save All</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Recent Adjustment History">
        <div class="overflow-x-auto"><table class="w-full min-w-[800px] text-sm"><thead><tr class="border-b text-left"><th class="px-2 py-2">Reference</th><th class="px-2 py-2">Product</th><th class="px-2 py-2">Warehouse</th><th class="px-2 py-2">Type</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">GRN</th><th class="px-2 py-2">By</th><th class="px-2 py-2">When</th></tr></thead><tbody>@forelse ($this->recentHistory() as $adjustment)<tr class="border-b"><td class="px-2 py-2">{{ $adjustment->reference }}</td><td class="px-2 py-2">{{ $adjustment->product?->sku }}</td><td class="px-2 py-2">{{ $adjustment->warehouse?->name }}</td><td class="px-2 py-2">{{ str_replace('_', ' ', $adjustment->type) }}</td><td class="px-2 py-2">{{ abs($adjustment->available_delta) }}</td><td class="px-2 py-2">{{ $adjustment->grn_reference ?? '—' }}</td><td class="px-2 py-2">{{ $adjustment->performedBy?->name }}</td><td class="px-2 py-2">{{ $adjustment->performed_at?->format('d M Y H:i') }}</td></tr>@empty<tr><td colspan="8" class="px-2 py-6 text-center text-gray-500">No stock adjustments yet.</td></tr>@endforelse</tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
