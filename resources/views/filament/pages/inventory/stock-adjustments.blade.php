<x-filament-panels::page>
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
            <label><span class="mb-1 block text-sm font-medium">Stock</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="stockFilter"><option value="">All stock</option><option value="saleable">Saleable &gt; 0</option><option value="damaged">Damaged &gt; 0</option><option value="reserved">Reserved &gt; 0</option></x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-sm font-medium">Rows per page</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="50">50</option></x-filament::input.select></x-filament::input.wrapper></label>
        </div>

        <form wire:submit="saveAll" class="space-y-4">
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-[1450px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300"><tr><th class="px-2 py-2">Select</th><th class="px-2 py-2">Product / SKU</th><th class="px-2 py-2">Brand</th><th class="px-2 py-2">Warehouse</th><th class="px-2 py-2">Saleable</th><th class="px-2 py-2">Damaged</th><th class="px-2 py-2">Reserved</th><th class="px-2 py-2">Stock Holder</th><th class="px-2 py-2">Adjustment Type</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">New Purchase?</th><th class="px-2 py-2">Reason</th><th class="px-2 py-2">Status</th></tr></thead>
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
                                <td class="px-2 py-3"><div class="font-semibold">{{ $row->product->name }}</div><div class="text-xs text-gray-500">{{ $row->product->sku }}</div>@if ($linked)<div class="text-xs font-semibold text-primary-600">Linked GRN</div>@endif</td>
                                <td class="px-2 py-3">{{ $row->product->brandRelation?->name ?? '—' }}</td>
                                <td class="px-2 py-3">{{ $row->warehouse?->name }}</td>
                                <td class="px-2 py-3 font-semibold">{{ $row->available_quantity }}</td>
                                <td class="px-2 py-3">{{ $row->damaged_quantity }}</td>
                                <td class="px-2 py-3">{{ $row->reserved_quantity }}</td>
                                <td class="px-2 py-3 text-xs">@forelse ($row->holder_labels as $holderLabel)<div>{{ $holderLabel }}</div>@empty<span class="text-gray-500">No allocation holder</span>@endforelse</td>
                                <td class="px-2 py-3"><x-filament::input.wrapper><x-filament::input.select wire:model.live="drafts.{{ $row->id }}.type" :disabled="! $mayAdjust"><option value="">No change</option><option value="saleable_increase">Saleable +</option><option value="saleable_decrease">Saleable −</option><option value="mark_damaged">Move to Damaged</option><option value="restore_damaged">Restore from Damaged</option></x-filament::input.select></x-filament::input.wrapper></td>
                                <td class="px-2 py-3"><x-filament::input.wrapper><x-filament::input type="number" min="1" step="1" wire:model.blur="drafts.{{ $row->id }}.quantity" :disabled="! $mayAdjust" aria-label="Quantity for {{ $row->product->sku }}" /></x-filament::input.wrapper>@error('drafts.'.$row->id.'.quantity')<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                                <td class="px-2 py-3">@if ($increase)<x-filament::input.wrapper><x-filament::input.select wire:model.live="drafts.{{ $row->id }}.is_new_purchase" :disabled="! $mayAdjust"><option value="">Choose</option><option value="yes">Yes</option><option value="no">No</option></x-filament::input.select></x-filament::input.wrapper>@if (($draft['is_new_purchase'] ?? null) === 'yes' && $this->canQuickPurchase())<a target="_blank" rel="noopener" class="mt-2 block text-xs font-semibold text-primary-600 hover:underline" href="{{ \App\Filament\Pages\Purchasing\QuickStockPurchase::getUrl(['product_id' => $row->product_id, 'warehouse_id' => $row->warehouse_id]) }}">Open Quick Stock Purchase</a>@endif @else<span class="text-gray-500">—</span>@endif</td>
                                <td class="min-w-56 px-2 py-3"><x-filament::input.wrapper><x-filament::input type="text" maxlength="2000" wire:model.blur="drafts.{{ $row->id }}.reason" :disabled="! $mayAdjust" placeholder="Physical count reason" /></x-filament::input.wrapper>@if ($increase && ($draft['is_new_purchase'] ?? null) === 'no' && ! $linked)<div class="mt-2"><label class="text-xs">Allocation holder</label><x-filament::input.wrapper><x-filament::input.select wire:model.live="drafts.{{ $row->id }}.allocation_account_id" :disabled="! $mayAdjust"><option value="">Select holder</option>@foreach ($this->increaseHolderOptions($row) as $accountId => $name)<option value="{{ $accountId }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>@if (app(\App\Services\Authorization\InventoryAuthorization::class)->allows(auth()->user(), \App\Enums\InventoryPermission::ViewFinancials))<div class="mt-2"><label class="text-xs">Valuation cost if balance is empty (AED)</label><x-filament::input.wrapper><x-filament::input type="text" wire:model.blur="drafts.{{ $row->id }}.valuation_unit_cost" /></x-filament::input.wrapper></div>@endif @endif</td>
                                <td class="px-2 py-3"><x-filament::badge :color="match ($status) { 'Ready', 'Saved' => 'success', 'New Purchase' => 'info', 'No Change' => 'gray', 'Reserved Stock Involved' => 'warning', default => 'danger' }">{{ $status }}</x-filament::badge>@error('drafts.'.$row->id)<div class="mt-1 text-xs text-danger-600">{{ $message }}</div>@enderror</td>
                            </tr>
                        @empty
                            <tr><td colspan="13" class="px-4 py-8 text-center text-gray-500">No stock rows match your Responsibility scope and filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $rows->onEachSide(1)->links() }}</div>
            @error('drafts')<div class="text-sm text-danger-600">{{ $message }}</div>@enderror
            <div class="flex justify-end"><x-filament::button type="submit" :disabled="! $mayAdjust">Save All</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Recent Adjustment History">
        <div class="overflow-x-auto"><table class="w-full min-w-[800px] text-sm"><thead><tr class="border-b text-left"><th class="px-2 py-2">Reference</th><th class="px-2 py-2">Product</th><th class="px-2 py-2">Warehouse</th><th class="px-2 py-2">Type</th><th class="px-2 py-2">Qty</th><th class="px-2 py-2">GRN</th><th class="px-2 py-2">By</th><th class="px-2 py-2">When</th></tr></thead><tbody>@forelse ($this->recentHistory() as $adjustment)<tr class="border-b"><td class="px-2 py-2">{{ $adjustment->reference }}</td><td class="px-2 py-2">{{ $adjustment->product?->sku }}</td><td class="px-2 py-2">{{ $adjustment->warehouse?->name }}</td><td class="px-2 py-2">{{ str_replace('_', ' ', $adjustment->type) }}</td><td class="px-2 py-2">{{ abs($adjustment->available_delta) }}</td><td class="px-2 py-2">{{ $adjustment->grn_reference ?? '—' }}</td><td class="px-2 py-2">{{ $adjustment->performedBy?->name }}</td><td class="px-2 py-2">{{ $adjustment->performed_at?->format('d M Y H:i') }}</td></tr>@empty<tr><td colspan="8" class="px-2 py-6 text-center text-gray-500">No stock adjustments yet.</td></tr>@endforelse</tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>
