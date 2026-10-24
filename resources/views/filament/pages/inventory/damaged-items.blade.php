<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Damaged Items', 'metricValue' => $summary['items'], 'metricIcon' => 'heroicon-o-exclamation-triangle', 'metricAccent' => 'rose', 'metricShowOpen' => false])
        @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Damaged Units', 'metricValue' => $summary['units'], 'metricIcon' => 'heroicon-o-cube-transparent', 'metricAccent' => 'red', 'metricShowOpen' => false])
        @include('filament.widgets.dashboard.metric-card', ['metricTitle' => 'Oldest Damaged', 'metricValue' => $summary['oldest'] ?? 'Not recorded', 'metricIcon' => 'heroicon-o-clock', 'metricAccent' => 'amber', 'metricShowOpen' => false])
    </div>

    <x-filament::section>
        <div class="grid gap-3 md:grid-cols-6">
            <x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Product or SKU" /></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="warehouseId"><option value="">All locations</option>@foreach($warehouses as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="source"><option value="">All sources</option>@foreach($sources as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="platformId"><option value="">All platforms</option>@foreach($platforms as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="status">@foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
            <x-filament::button color="gray" wire:click="resetFilters">Reset</x-filament::button>
        </div>
    </x-filament::section>

    <div class="max-w-full overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
        <table class="w-full min-w-[1320px] table-fixed text-sm">
            <thead class="bg-gray-50 text-left dark:bg-white/5"><tr class="whitespace-nowrap">
                @foreach(['SKU','Product / Model','Qty','Location','Source','Platform','Related Return / Order','Reason','Damaged Date','Days Damaged','Status',''] as $heading)
                    <th @class(['px-3 py-2.5 font-medium', 'w-28' => $loop->index === 0, 'w-64' => $loop->index === 1, 'w-16' => $loop->index === 2, 'w-36' => in_array($loop->index, [3, 4], true), 'w-32' => in_array($loop->index, [5, 8, 10], true), 'w-40' => $loop->index === 6, 'w-56' => $loop->index === 7, 'w-24' => $loop->index === 9, 'sticky right-0 z-10 w-44 bg-gray-50 dark:bg-gray-900' => $loop->last])>{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse($rows as $row)
                    <tr class="align-top">
                        <td class="whitespace-nowrap px-3 py-2.5 font-medium">{{ $row['sku'] }}</td>
                        <td class="px-3 py-2.5"><span class="line-clamp-2 break-words" title="{{ $row['product_name'] }}">{{ $row['product_name'] }}</span></td>
                        <td class="px-3 py-2.5 text-center">{{ $row['quantity'] }}</td>
                        <td class="px-3 py-2.5"><span class="block truncate" title="{{ $row['location'] }}">{{ $row['location'] }}</span></td>
                        <td class="px-3 py-2.5"><span class="block truncate" title="{{ $row['source_label'] }}">{{ $row['source_label'] }}</span></td>
                        <td class="px-3 py-2.5"><span class="block truncate" title="{{ $row['platform'] ?? '—' }}">{{ $row['platform'] ?? '—' }}</span></td>
                        <td class="px-3 py-2.5"><span class="block truncate" title="{{ $row['related_reference'] ?? '—' }}">{{ $row['related_reference'] ?? '—' }}</span></td>
                        <td class="px-3 py-2.5"><span class="line-clamp-2 break-words" title="{{ $row['reason'] }}">{{ $row['reason'] }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2.5">{{ $row['damaged_date'] ?? 'Not recorded' }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-center">{{ $row['days_damaged'] ?? '—' }}</td>
                        <td class="px-3 py-2.5"><x-filament::badge color="gray">{{ $row['status_label'] }}</x-filament::badge></td>
                        <td class="sticky right-0 bg-white px-3 py-2.5 dark:bg-gray-900"><div class="flex flex-wrap items-center justify-end gap-2">{{ ($this->viewDetailsAction)(['item' => $row]) }}@if($canSendToRepair && $row['row_kind'] === 'event' && $row['status'] === 'damaged' && $row['available_repair_quantity'] > 0){{ ($this->sendToRepairAction)(['damageId' => $row['id']]) }}@elseif($canSendToRepair && $row['row_kind'] === 'legacy' && $row['available_repair_quantity'] > 0){{ ($this->startLegacyRepairAction)(['inventoryId' => abs($row['id'])]) }}@endif</div></td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-4 py-8 text-center text-gray-500">No damaged items match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    <x-filament::section heading="Returns Needing Review" description="Items held for verification are separate from valued or sellable inventory.">
        <div class="max-w-full overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-gray-50 text-left dark:bg-white/5"><tr>
                    @foreach (['Return', 'Original ordered product', 'Actually received', 'Receiving Warehouse', 'Items Currently Held', 'Reason', 'Case / Evidence', 'Recorded by', 'Review'] as $heading)
                        <th class="px-3 py-2 font-medium">{{ $heading }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse ($quarantinedReturns as $exception)
                        <tr>
                            <td class="px-3 py-2"><a class="text-primary-600 underline" href="{{ \App\Filament\Resources\CustomerReturns\CustomerReturnResource::getUrl('view', ['record' => $exception->item->customer_return_id]) }}">{{ $exception->item->customerReturn->reference }}</a></td>
                            <td class="px-3 py-2">{{ $exception->item->sku_snapshot }} — {{ $exception->item->product_name_snapshot }}</td>
                            <td class="px-3 py-2">{{ $exception->actual_product_name ?: ($exception->problem_type === 'empty_package' ? 'Empty package' : 'Unidentified item') }}</td>
                            <td class="px-3 py-2">{{ $exception->warehouse->name }}</td>
                            <td class="px-3 py-2">{{ $exception->currentCustodyQuantity() ?? 'Pending count' }}</td>
                            <td class="px-3 py-2">{{ str($exception->problem_type)->replace('_', ' ')->headline() }}</td>
                            <td class="px-3 py-2">{{ $exception->claim_case_reference ?: 'Not eligible for an automatic marketplace case' }}<br><span class="text-gray-500">{{ $exception->evidenceReadiness() }}</span></td>
                            <td class="px-3 py-2">{{ $exception->recordedBy?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $exception->status === 'awaiting_review' ? 'Owner/Admin review pending' : ($exception->disposition === 'retain_in_quarantine' ? 'Keep Item for Review' : str($exception->disposition)->replace('_', ' ')->headline()) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-3 py-6 text-center text-gray-500">No returns needing review are visible in your scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $quarantinedReturns->links() }}
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
