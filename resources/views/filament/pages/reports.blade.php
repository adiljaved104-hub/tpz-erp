<x-filament-panels::page>
    <div class="grid gap-4">
        <x-filament::section compact>
            <x-slot name="heading">Report Selection</x-slot>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="grid gap-1 text-sm font-medium md:col-span-2">
                    <span>Report</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="report" aria-label="Report">
                            @foreach ($reports as $group => $items)
                                <optgroup label="{{ $group }}">
                                    @foreach ($items as $key => $item)<option value="{{ $key }}">{{ $item['title'] }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <div class="flex items-end gap-2">
                    @if ($exportFormats !== [])
                        @if (in_array('xlsx', $exportFormats, true))<x-filament::button tag="a" size="sm" :href="$this->exportUrl('xlsx')">XLSX</x-filament::button>@endif
                        @if (in_array('csv', $exportFormats, true))<x-filament::button tag="a" size="sm" color="gray" outlined :href="$this->exportUrl('csv')">CSV</x-filament::button>@endif
                        @if (in_array('pdf', $exportFormats, true))<x-filament::button tag="a" size="sm" color="gray" outlined :href="$this->exportUrl('pdf')">PDF</x-filament::button>@endif
                    @else
                        <span class="text-sm text-gray-500">Export permission is not granted.</span>
                    @endif
                </div>
            </div>
        </x-filament::section>

        <x-filament::section compact>
            <x-slot name="heading">Filters</x-slot>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                @if ($definition['period'])
                    <div class="flex flex-wrap items-end gap-1 sm:col-span-2 lg:col-span-4 xl:col-span-6">
                        @foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $value => $label)
                            <x-filament::button size="xs" :color="$period === $value ? 'primary' : 'gray'" :outlined="$period !== $value" wire:click="setPeriod('{{ $value }}')">{{ $label }}</x-filament::button>
                        @endforeach
                    </div>
                    <label class="grid gap-1 text-sm"><span>Date From</span><x-filament::input.wrapper><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper>@error('from')<span class="text-danger-600">{{ $message }}</span>@enderror</label>
                    <label class="grid gap-1 text-sm"><span>Date To</span><x-filament::input.wrapper><x-filament::input type="date" wire:model="to" /></x-filament::input.wrapper>@error('to')<span class="text-danger-600">{{ $message }}</span>@enderror</label>
                @endif
                @foreach ($definition['filters'] as $filter)
                    @if ($filter === 'status')
                        <label class="grid gap-1 text-sm"><span>Status / Type</span><x-filament::input.wrapper><x-filament::input wire:model="status" placeholder="All statuses" /></x-filament::input.wrapper></label>
                    @elseif ($filter === 'employee')
                        <label class="grid gap-1 text-sm"><span>Employee</span><x-filament::input.wrapper><x-filament::input.select wire:model="employeeId"><option value="">All authorized</option>@foreach ($options['employees'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'team')
                        <label class="grid gap-1 text-sm"><span>Team</span><x-filament::input.wrapper><x-filament::input.select wire:model="teamId"><option value="">All authorized</option>@foreach ($options['teams'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'platform')
                        <label class="grid gap-1 text-sm"><span>Platform</span><x-filament::input.wrapper><x-filament::input.select wire:model="platformId"><option value="">All</option>@foreach ($options['platforms'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'product')
                        <div class="grid min-w-0 gap-1 text-sm sm:col-span-2">
                            <span>Product</span>
                            <div class="grid min-w-0 gap-2 sm:grid-cols-2">
                                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input wire:model.live.debounce.300ms="productSearch" placeholder="Type at least 2 characters" /></x-filament::input.wrapper>
                                <x-filament::input.wrapper><x-filament::input.select wire:model="productId"><option value="">All authorized</option>@foreach ($options['products'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                            </div>
                            <span class="text-xs font-normal text-gray-500">Search by SKU or Product name. Only authorized Products are returned.</span>
                        </div>
                    @elseif ($filter === 'brand')
                        <label class="grid gap-1 text-sm"><span>Brand</span><x-filament::input.wrapper><x-filament::input.select wire:model="brandId"><option value="">All</option>@foreach ($options['brands'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'warehouse')
                        <label class="grid gap-1 text-sm"><span>Warehouse / Location</span><x-filament::input.wrapper><x-filament::input.select wire:model="warehouseId"><option value="">All</option>@foreach ($options['warehouses'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'supplier')
                        <label class="grid gap-1 text-sm"><span>Supplier</span><x-filament::input.wrapper><x-filament::input.select wire:model="supplierId"><option value="">All</option>@foreach ($options['suppliers'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'category')
                        <label class="grid gap-1 text-sm"><span>Expense Category</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="category"><option value="">All categories</option>@foreach ($options['expenseCategories'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'cost_center')
                        <label class="grid gap-1 text-sm"><span>Cost Center</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="costCenter"><option value="">All cost centers</option>@foreach ($options['expenseCostCenters'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'channel')
                        <label class="grid gap-1 text-sm"><span>Sales Channel</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="channel"><option value="">All channels</option>@foreach ($options['webSalesChannels'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @elseif ($filter === 'office_account')
                        <label class="grid gap-1 text-sm"><span>Office Account</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="officeAccountId"><option value="">All accounts</option>@foreach ($options['officeFinanceAccounts'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    @endif
                @endforeach
                <div class="flex items-end"><x-filament::button size="sm" wire:click="applyFilters">Apply Filters</x-filament::button></div>
            </div>
        </x-filament::section>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($result->summary as $label => $value)
                <x-filament::section compact><div class="text-xl font-bold">{{ \App\Support\ReportValueFormatter::summary($result, $label, $value) }}</div><div class="text-xs text-gray-500">{{ $label }}</div></x-filament::section>
            @endforeach
        </div>

        <x-filament::section compact>
            <x-slot name="heading">{{ $result->title }} Preview</x-slot>
            <x-slot name="description">Showing up to {{ \App\Services\Reports\ReportQueryService::PREVIEW_LIMIT }} rows from {{ number_format($result->totalRows) }} authorized results.</x-slot>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach ($result->columns as $column)<th class="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold">{{ $column['label'] }}</th>@endforeach</tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($result->rows as $row)
                            <tr>@foreach ($result->columns as $column)<td class="max-w-xs px-3 py-2 align-top"><span class="line-clamp-2" title="{{ $row[$column['key']] ?? '' }}">@if (($column['type'] ?? null) === 'money' && ($row[$column['key']] ?? null) !== null)AED {{ number_format((float) $row[$column['key']], 2) }}@elseif (($column['type'] ?? null) === 'money_pkr' && ($row[$column['key']] ?? null) !== null)PKR {{ number_format((float) $row[$column['key']], 2) }}@elseif (($column['type'] ?? null) === 'money_aed' && ($row[$column['key']] ?? null) !== null)AED {{ number_format((float) $row[$column['key']], 2) }}@else{{ $row[$column['key']] ?? '—' }}@endif</span></td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($result->columns) }}" class="px-4 py-6 text-center text-gray-500">No records match the selected filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
