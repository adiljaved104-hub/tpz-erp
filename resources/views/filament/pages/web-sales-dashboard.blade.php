<x-filament-panels::page>
    <style>
        .web-sales-dashboard { display: grid; min-width: 0; max-width: 100%; gap: 1rem; }
        .web-sales-dashboard > * { min-width: 0; max-width: 100%; }
        .web-sales-period-row { display: flex; min-width: 0; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .web-sales-period-tabs { display: flex; flex-wrap: wrap; gap: .375rem; }
        .web-sales-custom-range { display: flex; min-width: 0; flex-wrap: wrap; align-items: end; gap: .625rem; }
        .web-sales-date-field { display: grid; width: min(100%, 12rem); min-width: 0; gap: .25rem; font-size: .75rem; font-weight: 600; }
        .web-sales-range-label { margin-left: auto; font-size: .8125rem; opacity: .7; white-space: nowrap; }
        .web-sales-loading { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; opacity: .7; }
        .web-sales-loading-dot { width: .5rem; height: .5rem; border-radius: 9999px; background: currentColor; animation: web-sales-pulse 1s ease-in-out infinite; }
        .web-sales-kpi-grid,
        .web-sales-status-grid,
        .web-sales-detail-grid { display: grid; min-width: 0; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        .web-sales-kpi-grid > *,
        .web-sales-status-grid > *,
        .web-sales-detail-grid > * { min-width: 0; }
        .web-sales-metric-card { height: 100%; }
        .web-sales-metric-card > * { height: 100%; }
        .web-sales-metric-value { overflow-wrap: anywhere; font-size: 1.5rem; line-height: 1.15; font-weight: 700; letter-spacing: -.025em; }
        .web-sales-status-value { font-size: 1.25rem; line-height: 1.2; font-weight: 700; }
        .web-sales-metric-label { margin-top: .25rem; font-size: .75rem; line-height: 1rem; font-weight: 500; opacity: .65; }
        .web-sales-table-wrap { min-width: 0; max-width: 100%; overflow-x: auto; }
        .web-sales-table { width: 100%; min-width: 36rem; border-collapse: collapse; font-size: .8125rem; }
        .web-sales-detail-grid .web-sales-table { min-width: 32rem; }
        .web-sales-table th { padding: .625rem .75rem; border-bottom: 1px solid rgb(156 163 175 / .25); font-size: .7rem; line-height: 1rem; font-weight: 600; letter-spacing: .035em; text-transform: uppercase; opacity: .7; white-space: nowrap; }
        .web-sales-table td { padding: .7rem .75rem; border-bottom: 1px solid rgb(156 163 175 / .16); vertical-align: middle; }
        .web-sales-table tbody tr:last-child td { border-bottom: 0; }
        .web-sales-table .web-sales-number { text-align: right; white-space: nowrap; }
        .web-sales-employee { min-width: 11rem; font-weight: 600; }
        .web-sales-product { min-width: 14rem; max-width: 24rem; }
        .web-sales-product-label { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-height: 1.2rem; overflow-wrap: anywhere; }
        .web-sales-empty { padding: 1.25rem .75rem; text-align: center; font-size: .8125rem; opacity: .65; }

        @keyframes web-sales-pulse { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }

        @media (min-width: 40rem) {
            .web-sales-kpi-grid,
            .web-sales-status-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 64rem) {
            .web-sales-kpi-grid,
            .web-sales-status-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (min-width: 80rem) {
            .web-sales-kpi-grid,
            .web-sales-status-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .web-sales-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 39.999rem) {
            .web-sales-custom-range,
            .web-sales-date-field { width: 100%; }
            .web-sales-range-label { width: 100%; margin-left: 0; white-space: normal; }
        }
    </style>

    <div class="web-sales-dashboard" data-web-sales-dashboard>
        <x-filament::section compact>
            <div class="web-sales-period-row">
                <div class="web-sales-period-tabs" role="tablist" aria-label="Web Sales dashboard period">
                    @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'month' => 'This Month', 'custom' => 'Custom'] as $value => $label)
                        <x-filament::button type="button" size="xs" :color="$period === $value ? 'primary' : 'gray'" :outlined="$period !== $value" wire:click="setPeriod('{{ $value }}')" wire:loading.attr="disabled" role="tab" :aria-selected="$period === $value ? 'true' : 'false'">
                            {{ $label }}
                        </x-filament::button>
                    @endforeach
                </div>

                @if ($period === 'custom')
                    <div class="web-sales-custom-range" data-web-sales-custom-range>
                        <label class="web-sales-date-field">
                            <span>From</span>
                            <x-filament::input.wrapper :valid="! $errors->has('from')"><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper>
                            @error('from') <span style="font-weight: 400; color: rgb(220 38 38);">{{ $message }}</span> @enderror
                        </label>
                        <label class="web-sales-date-field">
                            <span>To</span>
                            <x-filament::input.wrapper :valid="! $errors->has('to')"><x-filament::input type="date" wire:model="to" /></x-filament::input.wrapper>
                            @error('to') <span style="font-weight: 400; color: rgb(220 38 38);">{{ $message }}</span> @enderror
                        </label>
                        <x-filament::button type="button" size="sm" wire:click="applyRange" wire:loading.attr="disabled" wire:target="applyRange">Apply</x-filament::button>
                    </div>
                @endif

                <span class="web-sales-range-label">{{ $rangeLabel }}</span>
                <span class="web-sales-loading" wire:loading wire:target="setPeriod,applyRange">
                    <span class="web-sales-loading-dot" aria-hidden="true"></span>
                    Updating
                </span>
            </div>
        </x-filament::section>

        <div class="web-sales-kpi-grid" data-web-sales-summary>
            @foreach (['total_orders' => 'Total Orders', 'units_sold' => 'Units Sold', 'revenue' => 'Revenue', 'cogs' => 'COGS', 'gross_profit' => 'Gross Profit', 'operating_expenses' => 'Operating Expenses', 'net_profit' => 'Net Profit'] as $key => $label)
                @if ($metrics['summary'][$key] !== null)
                    <div class="web-sales-metric-card" data-web-sales-metric="{{ $key }}">
                        <x-filament::section compact>
                            <div class="web-sales-metric-value">{{ in_array($key, ['revenue', 'cogs', 'gross_profit', 'operating_expenses', 'net_profit'], true) ? 'AED '.number_format((float) $metrics['summary'][$key], 2) : number_format((int) $metrics['summary'][$key]) }}</div>
                            <div class="web-sales-metric-label">{{ $label }}</div>
                        </x-filament::section>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="web-sales-status-grid" data-web-sales-statuses>
            @foreach (['new' => 'New', 'confirmed' => 'Confirmed', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'] as $key => $label)
                <div class="web-sales-metric-card" data-web-sales-status="{{ $key }}">
                    <x-filament::section compact>
                        <div class="web-sales-status-value">{{ number_format((int) $metrics['statuses'][$key]) }}</div>
                        <div class="web-sales-metric-label">{{ $label }}</div>
                    </x-filament::section>
                </div>
            @endforeach
        </div>

        <x-filament::section heading="Employee Performance" description="Authorized Web Sales activity for the selected period." compact>
            @if ($metrics['employees']->isEmpty())
                <div class="web-sales-empty">No employee Web Sales activity in this period.</div>
            @else
                <div class="web-sales-table-wrap">
                    <table class="web-sales-table" data-web-sales-employee-performance>
                        <thead><tr><th style="text-align: left;">Employee</th><th class="web-sales-number">Orders</th><th class="web-sales-number">Units</th>@if ($metrics['can_revenue']) <th class="web-sales-number">Revenue</th> @endif @if ($metrics['can_cost']) <th class="web-sales-number">COGS</th> @endif @if ($metrics['can_profit']) <th class="web-sales-number">Gross Profit</th> @endif</tr></thead>
                        <tbody>
                            @foreach ($metrics['employees'] as $row)
                                <tr><td class="web-sales-employee">{{ $row['employee'] }}</td><td class="web-sales-number">{{ number_format((int) $row['orders']) }}</td><td class="web-sales-number">{{ number_format((int) $row['units']) }}</td>@if ($metrics['can_revenue']) <td class="web-sales-number">AED {{ number_format((float) $row['revenue'], 2) }}</td> @endif @if ($metrics['can_cost']) <td class="web-sales-number">AED {{ number_format((float) $row['cogs'], 2) }}</td> @endif @if ($metrics['can_profit']) <td class="web-sales-number">AED {{ number_format((float) $row['gross_profit'], 2) }}</td> @endif</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @if ($metrics['can_net_profit'])
            <x-filament::section heading="Web Sales Expenses" description="Active expenses allocated to Web Sales for the selected period." compact>
                @if ($metrics['expense_breakdown']->isEmpty())
                    <div class="web-sales-empty">No Web Sales expenses in this period.</div>
                @else
                    <div class="web-sales-table-wrap">
                        <table class="web-sales-table" data-web-sales-expenses>
                            <thead><tr><th style="text-align: left;">Category</th><th class="web-sales-number">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($metrics['expense_breakdown'] as $row)
                                    <tr><td style="font-weight: 600;">{{ $row['category'] }}</td><td class="web-sales-number">AED {{ number_format((float) $row['amount'], 2) }}</td></tr>
                                @endforeach
                                <tr><td style="font-weight: 700;">Total Expenses</td><td class="web-sales-number" style="font-weight: 700;">AED {{ number_format((float) $metrics['summary']['operating_expenses'], 2) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endif

        <div class="web-sales-detail-grid">
            <x-filament::section heading="Sales by Channel" compact>
                @if ($metrics['channels']->isEmpty())
                    <div class="web-sales-empty">No Web Sales channels have activity in this period.</div>
                @else
                    <div class="web-sales-table-wrap">
                        <table class="web-sales-table" data-web-sales-channels>
                            <thead><tr><th style="text-align: left;">Channel</th><th class="web-sales-number">Orders</th><th class="web-sales-number">Units</th>@if ($metrics['can_revenue']) <th class="web-sales-number">Revenue</th> @endif @if ($metrics['can_profit']) <th class="web-sales-number">Gross Profit</th> @endif</tr></thead>
                            <tbody>
                                @foreach ($metrics['channels'] as $row)
                                    <tr><td style="font-weight: 600;">{{ $row['channel'] }}</td><td class="web-sales-number">{{ number_format((int) $row['orders']) }}</td><td class="web-sales-number">{{ number_format((int) $row['units']) }}</td>@if ($metrics['can_revenue']) <td class="web-sales-number">AED {{ number_format((float) $row['revenue'], 2) }}</td> @endif @if ($metrics['can_profit']) <td class="web-sales-number">AED {{ number_format((float) $row['gross_profit'], 2) }}</td> @endif</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section heading="Top Products" compact>
                @if ($metrics['products']->isEmpty())
                    <div class="web-sales-empty">No fulfilled Web Sales in this period.</div>
                @else
                    <div class="web-sales-table-wrap">
                        <table class="web-sales-table" data-web-sales-products>
                            <thead><tr><th style="text-align: left;">Product</th><th class="web-sales-number">Units</th>@if ($metrics['can_revenue']) <th class="web-sales-number">Revenue</th> @endif @if ($metrics['can_profit']) <th class="web-sales-number">Gross Profit</th> @endif</tr></thead>
                            <tbody>
                                @foreach ($metrics['products'] as $row)
                                    <tr><td class="web-sales-product"><span class="web-sales-product-label" title="{{ $row['product'] }}">{{ $row['product'] }}</span></td><td class="web-sales-number">{{ number_format((int) $row['units']) }}</td>@if ($metrics['can_revenue']) <td class="web-sales-number">AED {{ number_format((float) $row['revenue'], 2) }}</td> @endif @if ($metrics['can_profit']) <td class="web-sales-number">AED {{ number_format((float) $row['gross_profit'], 2) }}</td> @endif</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
