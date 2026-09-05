<x-filament-panels::page>
    @php($filterDefinitions = $this->filterDefinitions())
    @php($rows = $this->rows())

    @if ($filterDefinitions !== [])
        <x-filament::section compact>
            <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
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
    @endif

    @if ($rows === [])
        <x-filament::section compact><div class="py-8 text-center text-sm text-gray-500">No records match the current filters.</div></x-filament::section>
    @else
        <div class="max-w-full overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10" style="max-width: 100%; overflow-x: auto;" data-testid="purchasing-report-table">
            <table class="w-full min-w-[760px] divide-y divide-gray-200 text-sm dark:divide-white/10" style="width: 100%; min-width: {{ max(760, count($rows[0]) * 125) }}px; border-collapse: separate; border-spacing: 0;">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach (array_keys($rows[0]) as $column)<th class="whitespace-nowrap px-3 py-3 text-left font-semibold" style="min-width: {{ match ($column) { 'product', 'product_name', 'name' => '240px', 'sku' => '115px', 'received_at' => '145px', 'accepted_quantity', 'damaged_quantity', 'rejected_quantity' => '110px', default => '125px' } }}; white-space: nowrap;">{{ $column === 'view' ? 'Action' : str($column)->replace('_', ' ')->title() }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr>@foreach ($row as $column => $value)<td @class(['px-3 py-3 align-top', 'max-w-72' => in_array($column, ['product', 'product_name', 'name'], true), 'whitespace-nowrap' => ! in_array($column, ['product', 'product_name', 'name'], true)])>@if ($column === 'view')<x-filament::button tag="a" size="xs" :href="$value">View GRN</x-filament::button>@elseif(in_array($column, ['product', 'product_name', 'name'], true))<span class="line-clamp-2" title="{{ $value }}">{{ \Illuminate\Support\Str::limit((string) $value, 72) }}</span>@else{{ $value }}@endif</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
