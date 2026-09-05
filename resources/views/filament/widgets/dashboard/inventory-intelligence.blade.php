@if ($inventory_intelligence !== null)
    <x-filament::section compact data-dashboard-section="inventory-intelligence">
        <x-slot name="heading">{{ ! $inventory_intelligence['can_select_scope'] && $inventory_intelligence['scope'] === 'employee' ? 'My Inventory Intelligence' : 'Inventory Intelligence' }}</x-slot>
        <x-slot name="description">{{ $inventory_intelligence['scope_label'] }} scope · Low stock threshold: {{ $inventory_intelligence['threshold'] }} sellable units</x-slot>

        @if ($inventory_intelligence['can_select_scope'])
            <div class="erp-dashboard-controls" data-inventory-scope-controls>
                <label class="erp-dashboard-control-label">
                    <span>Inventory Scope</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="inventoryScope">
                            <option value="company">{{ $inventory_intelligence['company_scope_label'] }}</option>
                            <option value="team">Team</option>
                            <option value="employee">Employee</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @if ($inventoryScope === 'team')
                    <label class="erp-dashboard-control-label">
                        <span>Team</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="inventoryTeamId">
                                <option value="">Select Team</option>
                                @foreach ($inventory_intelligence['team_options'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @elseif ($inventoryScope === 'employee')
                    <label class="erp-dashboard-control-label">
                        <span>Employee</span>
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="inventoryEmployeeId">
                                <option value="">Select Employee</option>
                                @foreach ($inventory_intelligence['employee_options'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                @endif
            </div>
        @endif

        <div class="erp-dashboard-inventory-grid" data-inventory-intelligence-grid>
            <div class="erp-dashboard-inventory-panel">
                <x-filament::section compact>
                    <x-slot name="heading">Inventory Attention</x-slot>
                    <div class="erp-dashboard-list">
                        @forelse ($inventory_intelligence['attention'] as $item)
                            <a href="{{ $item['url'] }}" class="erp-dashboard-list-row">
                                <div class="erp-dashboard-list-main">
                                    <div class="erp-dashboard-list-sku">{{ $item['sku'] }}</div>
                                    <div class="erp-dashboard-list-title" title="{{ $item['product'] }}">{{ $item['product_label'] }}</div>
                                    <div class="erp-dashboard-list-meta">Sellable: {{ number_format($item['sellable']) }} · Reserved: {{ number_format($item['reserved']) }}</div>
                                </div>
                                <div class="erp-dashboard-list-status"><x-filament::badge :color="$item['sellable'] <= 0 ? 'danger' : 'warning'">{{ $item['state'] }}</x-filament::badge></div>
                            </a>
                        @empty
                            <div class="erp-dashboard-list-row erp-dashboard-empty"><span class="erp-dashboard-list-meta">No inventory alerts in this scope.</span></div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
            <div class="erp-dashboard-inventory-panel">
                <x-filament::section compact>
                    <x-slot name="heading">Top-Selling Products</x-slot>
                    <div class="erp-dashboard-list">
                        @forelse ($inventory_intelligence['top_sellers'] as $item)
                            <a href="{{ $item['url'] }}" class="erp-dashboard-list-row">
                                <div class="erp-dashboard-list-main">
                                    <div class="erp-dashboard-list-sku">{{ $item['sku'] }}</div>
                                    <div class="erp-dashboard-list-title" title="{{ $item['product'] }}">{{ $item['product_label'] }}</div>
                                    <div class="erp-dashboard-list-meta">Sold: {{ number_format($item['sold_quantity']) }}</div>
                                </div>
                                <div class="erp-dashboard-list-status"><x-filament::badge color="success">Selling</x-filament::badge></div>
                            </a>
                        @empty
                            <div class="erp-dashboard-list-row erp-dashboard-empty"><span class="erp-dashboard-list-meta">No fulfilled sales in this period.</span></div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
            <div class="erp-dashboard-inventory-panel">
                <x-filament::section compact>
                    <x-slot name="heading">Fast Selling / Low Stock</x-slot>
                    <div class="erp-dashboard-list">
                        @forelse ($inventory_intelligence['fast_selling_low_stock'] as $item)
                            <a href="{{ $item['url'] }}" class="erp-dashboard-list-row">
                                <div class="erp-dashboard-list-main">
                                    <div class="erp-dashboard-list-sku">{{ $item['sku'] }}</div>
                                    <div class="erp-dashboard-list-title" title="{{ $item['product'] }}">{{ $item['product_label'] }}</div>
                                    <div class="erp-dashboard-list-meta">Sold: {{ number_format($item['sold_quantity']) }} · Sellable now: {{ number_format($item['sellable']) }}</div>
                                </div>
                                <div class="erp-dashboard-list-status"><x-filament::badge :color="$item['sellable'] <= 0 ? 'danger' : 'warning'">{{ $item['state'] }}</x-filament::badge></div>
                            </a>
                        @empty
                            <div class="erp-dashboard-list-row erp-dashboard-empty"><span class="erp-dashboard-list-meta">No fast-selling low-stock products in this period.</span></div>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        </div>
    </x-filament::section>
@endif
