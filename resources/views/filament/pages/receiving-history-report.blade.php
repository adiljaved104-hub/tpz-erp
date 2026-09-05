<x-filament-panels::page>
    @php($filterDefinitions = $this->filterDefinitions())
    @php($rows = $this->rows())
    @php($columnLabels = ['grn' => 'GRN', 'accepted_quantity' => 'Accepted Qty', 'damaged_quantity' => 'Damaged Qty', 'rejected_quantity' => 'Rejected Qty'])

    <x-filament::section compact>
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($filterDefinitions as $property => $filter)
                <label class="min-w-0 space-y-1 text-sm font-medium">
                    <span>{{ $filter['label'] }}</span>
                    @if ($filter['type'] === 'date')
                        <input type="date" wire:model.live="{{ $property }}" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    @else
                        <select wire:model.live="{{ $property }}" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach ($filter['options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                    @endif
                </label>
            @endforeach
            <div><x-filament::button size="sm" color="gray" wire:click="resetReportFilters">Clear filters</x-filament::button></div>
        </div>
    </x-filament::section>

    @if ($rows === [])
        <x-filament::section compact><div class="py-8 text-center text-sm text-gray-500">No records match the current filters.</div></x-filament::section>
    @else
        <div class="max-w-full overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" style="max-width:100%;overflow-x:auto" data-testid="receiving-history-table">
            <table class="w-full min-w-[900px] divide-y divide-gray-200 text-sm dark:divide-white/10" style="width:100%;min-width:980px;border-collapse:separate;border-spacing:0">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach(array_keys($rows[0]) as $column)<th @class(['whitespace-nowrap px-3 py-3 text-left font-semibold', 'sticky right-0 z-20 bg-gray-50 dark:bg-gray-900' => $column === 'view']) style="min-width:{{ match($column) {'received_by' => '150px', 'accepted_quantity', 'damaged_quantity', 'rejected_quantity' => '120px', 'view' => '90px', default => '135px'} }};padding:.75rem;text-align:left;white-space:nowrap;border-bottom:1px solid rgba(128,128,128,.3);{{ $column === 'view' ? 'position:sticky;right:0;z-index:2;background:Canvas;' : '' }}">{{ $columnLabels[$column] ?? ($column === 'view' ? 'Action' : str($column)->replace('_', ' ')->title()) }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach($rows as $row)<tr>@foreach($row as $column => $value)<td @class(['whitespace-nowrap px-3 py-3', 'sticky right-0 z-10 bg-white shadow-[-4px_0_8px_rgba(0,0,0,.06)] dark:bg-gray-900' => $column === 'view']) style="padding:.75rem;vertical-align:middle;white-space:nowrap;border-bottom:1px solid rgba(128,128,128,.18);{{ $column === 'view' ? 'position:sticky;right:0;z-index:1;background:Canvas;' : '' }}">@if($column === 'view')<x-filament::button tag="a" size="xs" :href="$value">View</x-filament::button>@else{{ $value }}@endif</td>@endforeach</tr>@endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
