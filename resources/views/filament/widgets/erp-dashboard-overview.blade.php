@php
    $sections = [
        'sales' => ['heading' => 'Sales & Orders', 'description' => 'Order activity and common operational entry points.', 'keys' => ['orders', 'revenue', 'quick_purchase', 'record_return']],
        'inventory' => ['heading' => 'Inventory', 'description' => 'Current authorized stock position.', 'keys' => ['inventory_units', 'sellable_inventory', 'inventory_value', 'low_stock', 'out_of_stock', 'damaged', 'qc_pending']],
        'service' => ['heading' => 'Service & Claims', 'description' => 'Open operational cases requiring follow-up.', 'keys' => ['returns', 'claims', 'warranty', 'internal_repairs', 'complaints']],
        'work' => ['heading' => 'Work', 'description' => 'Tasks and communication in your authorized scope.', 'keys' => ['tasks', 'chat_unread', 'notifications']],
        'hr' => ['heading' => 'HR', 'description' => 'Attendance, leave, and acknowledgment status.', 'keys' => ['attendance_present', 'attendance_late', 'attendance_absent', 'pending_leave', 'warning_acknowledgments', 'notice_acknowledgments']],
    ];
    $icons = [
        'orders' => 'heroicon-o-shopping-bag', 'revenue' => 'heroicon-o-banknotes', 'quick_purchase' => 'heroicon-o-plus-circle', 'record_return' => 'heroicon-o-arrow-uturn-left',
        'inventory_units' => 'heroicon-o-cube', 'inventory_value' => 'heroicon-o-chart-bar-square',
        'sellable_inventory' => 'heroicon-o-archive-box', 'low_stock' => 'heroicon-o-exclamation-triangle', 'out_of_stock' => 'heroicon-o-x-circle',
        'damaged' => 'heroicon-o-exclamation-triangle', 'qc_pending' => 'heroicon-o-magnifying-glass-circle', 'marketplace_inventory' => 'heroicon-o-building-storefront',
        'returns' => 'heroicon-o-arrow-uturn-left', 'claims' => 'heroicon-o-shield-check',
        'warranty' => 'heroicon-o-wrench-screwdriver', 'internal_repairs' => 'heroicon-o-cog-6-tooth',
        'complaints' => 'heroicon-o-megaphone', 'tasks' => 'heroicon-o-clipboard-document-check',
        'chat_unread' => 'heroicon-o-chat-bubble-left-right', 'notifications' => 'heroicon-o-bell',
        'attendance_present' => 'heroicon-o-user-group', 'attendance_late' => 'heroicon-o-clock',
        'attendance_absent' => 'heroicon-o-user-minus', 'pending_leave' => 'heroicon-o-calendar-days',
        'warning_acknowledgments' => 'heroicon-o-exclamation-circle', 'notice_acknowledgments' => 'heroicon-o-document-check',
    ];
    $cardsByKey = $cards->keyBy('key');
    $nativeMode = app(\App\Services\Navigation\NavigationPreferenceService::class)->workspaceModeEnabled(auth()->user());
@endphp

<x-filament-widgets::widget>
    <style>
        .erp-dashboard-stack { display: grid; min-width: 0; max-width: 100%; gap: 1rem; }
        .erp-dashboard-stack > * { min-width: 0; max-width: 100%; }

        /* Classic Dashboard: warehouse breakdown uses real responsive cards,
           not unstyled links. Scoped to Classic only; native mode is unchanged. */
        .tpz-classic-warehouse-heading { margin: .8rem 0 .6rem; color: #334155; font-size: .9rem; font-weight: 700; }
        .dark .tpz-classic-warehouse-heading { color: #e2e8f0; }
        .tpz-classic-warehouse-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
            min-width: 0;
            margin-top: .75rem;
        }
        .tpz-classic-warehouse-grid > .tpz-dashboard-warehouse-tile {
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 2.25rem minmax(0, 1fr);
            align-content: center;
            align-items: center;
            column-gap: .7rem;
            row-gap: .18rem;
            min-width: 0;
            min-height: 6.2rem;
            padding: .85rem;
            border: 1px solid #dce6f0;
            border-radius: .85rem;
            background: #fff;
            color: #163654;
            text-decoration: none;
            box-shadow: 0 1px 3px rgb(15 45 70 / 6%);
            overflow-wrap: anywhere;
        }
        .tpz-classic-warehouse-grid .tpz-native-mini-icon {
            grid-row: 1 / span 2;
            display: grid;
            place-items: center;
            width: 2.25rem;
            height: 2.25rem;
            border-radius: .65rem;
            background: #fff;
            color: #1777b7;
        }
        .tpz-classic-warehouse-grid .tpz-native-mini-label {
            grid-column: 2;
            min-width: 0;
            font-size: .8rem;
            line-height: 1.22;
            font-weight: 650;
            color: #3e5b73;
            overflow-wrap: anywhere;
        }
        .tpz-classic-warehouse-grid .tpz-dashboard-warehouse-tile > strong {
            grid-column: 2;
            color: #102e4a;
            font-size: 1.5rem;
            line-height: 1.15;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .tpz-classic-warehouse-grid > a.tpz-dashboard-warehouse-tile:hover,
        .tpz-classic-warehouse-grid > a.tpz-dashboard-warehouse-tile:focus-visible {
            border-color: #67b1e1;
            box-shadow: 0 3px 10px rgb(25 74 115 / 12%);
        }
        .tpz-classic-warehouse-grid > a.tpz-dashboard-warehouse-tile:focus-visible { outline: 2px solid #1382bd; outline-offset: 2px; }
        .dark .tpz-classic-warehouse-grid > .tpz-dashboard-warehouse-tile,
        .dark .tpz-classic-warehouse-grid > .tpz-dashboard-warehouse-tile:nth-child(n) {
            background: #1c344a; border-color: #3c566f; color: #f1f7ff;
        }
        .dark .tpz-classic-warehouse-grid .tpz-native-mini-icon { background: #24475e; color: #9cdeff; }
        .dark .tpz-classic-warehouse-grid .tpz-native-mini-label { color: #c9d9e8; }
        .dark .tpz-classic-warehouse-grid .tpz-dashboard-warehouse-tile > strong { color: #fff; }
        @media (max-width: 75rem) { .tpz-classic-warehouse-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 56rem) { .tpz-classic-warehouse-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 36rem) { .tpz-classic-warehouse-grid { grid-template-columns: minmax(0, 1fr); } }

        .erp-dashboard-header-actions, .erp-dashboard-period, .erp-dashboard-attention-action { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .erp-dashboard-attention-grid { display: grid; min-width: 0; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        .erp-dashboard-attention-grid > * { min-width: 0; }
        .erp-dashboard-attention-row { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: .75rem; }
        .erp-dashboard-attention-copy { min-width: 0; }
        .erp-dashboard-attention-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .875rem; font-weight: 600; }
        .erp-dashboard-attention-help { margin-top: .125rem; font-size: .75rem; opacity: .65; }
        .erp-dashboard-status-icon { width: 1.5rem; height: 1.5rem; opacity: .7; }
        .erp-dashboard-responsibilities { display: flex; flex-wrap: wrap; gap: .5rem; }
        .erp-dashboard-muted { font-size: .875rem; opacity: .65; }
        .erp-dashboard-controls { display: flex; min-width: 0; flex-wrap: wrap; align-items: end; gap: .75rem; margin-bottom: 1rem; }
        .erp-dashboard-control-label { display: grid; width: min(100%, 15rem); min-width: 0; gap: .3rem; font-size: .75rem; font-weight: 600; }
        .erp-dashboard-inventory-grid { display: grid; min-width: 0; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        .erp-dashboard-inventory-grid > * { min-width: 0; }
        .erp-dashboard-inventory-panel { min-width: 0; height: 100%; }
        .erp-dashboard-inventory-panel > * { height: 100%; }
        .erp-dashboard-list { display: grid; gap: .5rem; }
        .erp-dashboard-list-row { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: .625rem; padding: .55rem .65rem; border: 1px solid rgb(156 163 175 / .25); border-radius: .6rem; }
        .erp-dashboard-list-main { min-width: 0; flex: 1 1 auto; }
        .erp-dashboard-list-sku { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; line-height: 1rem; font-weight: 700; }
        .erp-dashboard-list-title { display: -webkit-box; overflow: hidden; margin-top: .1rem; -webkit-box-orient: vertical; -webkit-line-clamp: 2; font-size: .8125rem; line-height: 1.05rem; font-weight: 500; overflow-wrap: anywhere; }
        .erp-dashboard-list-meta { margin-top: .2rem; font-size: .75rem; line-height: 1rem; opacity: .65; overflow-wrap: anywhere; }
        .erp-dashboard-list-status { flex: 0 0 auto; max-width: 7rem; }
        .erp-dashboard-empty { min-height: 0; padding-block: .5rem; }
        .erp-dashboard-widget { position: relative; min-width: 0; transition: opacity 150ms ease, transform 150ms ease; }
        .erp-dashboard-widget[data-dragging='true'] { opacity: .45; transform: scale(.995); }
        .erp-dashboard-drag-handle { position: absolute; z-index: 20; top: .55rem; right: .55rem; display: inline-flex; align-items: center; gap: .3rem; padding: .3rem .5rem; border: 1px solid rgb(156 163 175 / .35); border-radius: .5rem; background: rgb(255 255 255 / .96); color: rgb(75 85 99); font-size: .7rem; font-weight: 600; cursor: grab; box-shadow: 0 1px 2px rgb(0 0 0 / .06); }
        .dark .erp-dashboard-drag-handle { background: rgb(17 24 39 / .96); color: rgb(209 213 219); }
        .erp-dashboard-drag-handle:active { cursor: grabbing; }
        .erp-dashboard-customizer-grid { display: grid; min-width: 0; gap: .75rem; }
        .erp-dashboard-customizer-group { min-width: 0; padding: .75rem; border: 1px solid rgb(156 163 175 / .25); border-radius: .75rem; }
        .erp-dashboard-customizer-heading { margin-bottom: .5rem; font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
        .erp-dashboard-customizer-list { display: grid; gap: .45rem; }
        .erp-dashboard-customizer-row { display: flex; min-width: 0; align-items: center; justify-content: space-between; gap: .75rem; padding: .55rem .65rem; border-radius: .6rem; background: rgb(156 163 175 / .08); }
        .erp-dashboard-customizer-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .85rem; font-weight: 600; }
        .erp-dashboard-customizer-actions { display: flex; flex: none; align-items: center; gap: .35rem; }
        .erp-dashboard-empty-state { padding: 2rem 1rem; text-align: center; }
        .tpz-dashboard-date-filters { display: flex; flex: 0 1 auto; min-width: 0; flex-direction: column; align-items: flex-end; gap: .45rem; }
        .tpz-dashboard-date-filters .tpz-native-period { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .4rem; }
        .tpz-dashboard-custom-period { display: flex; flex-wrap: wrap; align-items: end; justify-content: flex-end; gap: .4rem; }
        .tpz-dashboard-custom-period label { display: flex; flex-direction: column; gap: .2rem; font-size: .72rem; font-weight: 650; }
        .tpz-dashboard-period-error { color: #b91c1c; font-size: .72rem; }
        @media (max-width: 47.999rem) {
            .tpz-dashboard-date-filters { align-items: flex-start; width: 100%; }
            .tpz-dashboard-date-filters .tpz-native-period, .tpz-dashboard-custom-period { justify-content: flex-start; }
        }


        /* Inventory breakdown uses the same dashboard panel language in both modes.
           Explicit scoped styles avoid missing utility classes in the compiled Filament theme. */
        .tpz-marketplace-mini-list { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .45rem; }
        .tpz-marketplace-mini-chip { display: inline-flex; max-width: 100%; align-items: center; gap: .3rem; padding: .18rem .38rem; border-radius: .4rem; background: #eaf3fd; color: #315676; font-size: .66rem; line-height: 1.1rem; }
        .tpz-marketplace-mini-name { max-width: 6.5rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tpz-marketplace-mini-chip strong { flex: none; font-weight: 800; color: #123b62; }
        .dark .tpz-marketplace-mini-chip { background: #263a50; color: #d1e6fb; }
        .dark .tpz-marketplace-mini-chip strong { color: #f4f9ff; }
        /* Stable Owner-approved native dashboard design: one Inventory section,
           consistently sized cards and the reference's stronger pastel accents. */
        .tpz-reference-home .tpz-dashboard-inventory-unified {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: .4rem;
            min-width: 0;
            align-items: stretch;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified > .tpz-native-mini-metric {
            box-sizing: border-box;
            min-width: 0;
            min-height: 4.25rem;
            padding: .4rem;
            border: 1px solid #d3e5f6;
            border-radius: .8rem;
            background: #e8f3ff;
            display: grid;
            grid-template-columns: 1.4rem minmax(0,1fr);
            align-content: start;
            gap: .12rem .35rem;
            color: #12395e;
            text-decoration: none;
            box-shadow: 0 1px 2px rgb(31 65 100 / 4%);
            transition: border-color .15s, box-shadow .15s;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified > a.tpz-native-mini-metric:hover {
            border-color: #79bdee;
            box-shadow: 0 3px 9px rgb(31 65 100 / 12%);
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-icon {
            width: 1.5rem; height: 1.5rem; border-radius: .45rem;
            display: grid; place-items: center; grid-row: 1 / span 3;
            background: rgb(255 255 255 / 78%);
            color: #1675c1;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-label {
            font-size: .65rem; font-weight: 700; line-height: 1.19;
            color: #31516f; overflow-wrap: anywhere;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric > strong {
            grid-column: 2; font-size: 1.09rem; line-height: 1.19;
            font-weight: 800; color: #092f55; overflow-wrap: anywhere;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--teal {
            background: #e7f8ed; border-color: #c9edd5;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--teal .tpz-native-mini-icon { color: #168454; }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--violet {
            background: #f0ecff; border-color: #dfd5fb;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--violet .tpz-native-mini-icon { color: #7252c1; }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--amber {
            background: #fff5df; border-color: #f6e0b2;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--amber .tpz-native-mini-icon { color: #b47c19; }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--rose {
            background: #ffedef; border-color: #f6d4db;
        }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric--rose .tpz-native-mini-icon { color: #c8415c; }
        .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-dashboard-warehouse-tile {
            background: #e8f3ff; border-color: #d3e5f6;
        }
        .tpz-reference-home .tpz-dashboard-marketplace-chips {
            display: flex; grid-column: 2; flex-wrap: wrap; gap: .2rem; min-width: 0;
        }
        .tpz-reference-home .tpz-dashboard-marketplace-chips small {
            max-width: 100%; overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap; padding: .1rem .25rem; border-radius: .3rem;
            background: #d4e8ff; font-size: .61rem; color: #244d76;
        }
        .tpz-reference-home .tpz-dashboard-marketplace-chips b { font-weight: 800; }
        .dark .tpz-reference-home .tpz-dashboard-inventory-unified > .tpz-native-mini-metric {
            background: #21394f; border-color: #3b5872;
        }
        .dark .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-label,
        .dark .tpz-reference-home .tpz-dashboard-inventory-unified .tpz-native-mini-metric > strong { color: #edf6ff; }
        /* Keep eight cards on desktop at 100% zoom. Reduce columns only
           when the viewport truly cannot fit the compact tiles. */
        @media (max-width: 73.999rem) {
            .tpz-reference-home .tpz-dashboard-inventory-unified { grid-template-columns: repeat(4,minmax(0,1fr)); }
        }
        @media (max-width: 47.999rem) {
            .tpz-reference-home .tpz-dashboard-inventory-unified { grid-template-columns: repeat(2,minmax(0,1fr)); }
        }
        @media (max-width: 29.999rem) {
            .tpz-reference-home .tpz-dashboard-inventory-unified { grid-template-columns: minmax(0,1fr); }
        }

        @media (min-width: 40rem) {
            .erp-dashboard-attention-grid, .erp-dashboard-customizer-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 64rem) {
            .erp-dashboard-inventory-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .erp-dashboard-attention-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 47.999rem) {
            .erp-dashboard-drag-handle { display: none; }
            .erp-dashboard-control-label { width: 100%; }
            .erp-dashboard-list-row, .erp-dashboard-attention-row { align-items: flex-start; }
            .erp-dashboard-attention-row { flex-wrap: wrap; }
            .erp-dashboard-customizer-row { align-items: flex-start; flex-direction: column; }
            .erp-dashboard-customizer-actions { width: 100%; justify-content: flex-end; }
        }
    </style>

    @unless($nativeMode)
    @if($quick_actions !== [])
        <section class="mb-4" aria-label="Quick actions">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-bold text-gray-950 dark:text-white">Quick actions</h2>
                <span class="text-xs text-gray-500 dark:text-gray-400">Available for your role</span>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach($quick_actions as $action)
                    <a href="{{ $action['url'] }}" class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-800 shadow-sm transition hover:border-primary-400 hover:shadow-md dark:border-white/10 dark:bg-gray-900 dark:text-white">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                            <x-filament::icon :icon="$action['icon']" class="size-5" />
                        </span>
                        <span>{{ $action['label'] }}</span>
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="ml-auto size-4 opacity-60" />
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if($recent_activity['enabled'])
        <section class="mb-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900" aria-label="Recent inventory activity">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">Recent inventory activity</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Stock movements within your authorized responsibility.</p>
                </div>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse($recent_activity['movements'] as $movement)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="dashboard-movement-{{ $movement->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $movement->product?->sku ?? $movement->reference }}</p>
                            <p class="text-xs text-gray-500">{{ $movement->movement_type?->label() ?? 'Movement' }} · {{ $movement->warehouse?->name ?? 'Warehouse' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold tabular-nums">{{ number_format($movement->quantity) }} unit(s)</p>
                            <p class="text-xs text-gray-500">{{ $movement->occurred_at?->format('d M Y H:i') ?? '—' }}</p>
                        </div>
                    </div>
                @empty
                    <p class="py-4 text-sm text-gray-500">No recent inventory movements in your scope.</p>
                @endforelse
            </div>
        </section>
    @endif

    @endunless

    <div class="erp-dashboard-stack" data-dashboard-role="{{ $role_label }}" data-dashboard-customizing="{{ $customize_mode ? 'true' : 'false' }}">
        @if ($nativeMode)
            <div class="tpz-reference-home tpz-reference-home--final">

                @php
                    // Use the user's display timezone, falling back to the company timezone.
                    // Time-of-day greeting changes when this Livewire dashboard refreshes.
                    $localNow = \Carbon\CarbonImmutable::now(app(\App\Services\UserTimezoneResolver::class)->resolve(auth()->user()));
                    $greeting = match (true) {
                        $localNow->hour < 12 => 'Good morning',
                        $localNow->hour < 17 => 'Good afternoon',
                        default => 'Good evening',
                    };
                @endphp
                <div class="tpz-home-welcome tpz-home-welcome--greeting">
                    <div>
                        <h1>{{ $greeting }}, {{ auth()->user()->name }} <span aria-hidden="true">👋</span></h1>
                        <p>Here's what's happening in TPZ ERP today.</p>
                    </div>
                    <div class="tpz-dashboard-date-filters">
                        <time datetime="{{ $localNow->toDateString() }}">{{ $localNow->format('D, d M Y') }}</time>
                        <div class="tpz-native-period" aria-label="Dashboard date range">
                            @foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'custom' => 'Custom'] as $key => $label)
                                <x-filament::button type="button" size="xs" :color="$period === $key ? 'primary' : 'gray'" :outlined="$period !== $key" wire:click="setPeriod('{{ $key }}')" :aria-pressed="$period === $key ? 'true' : 'false'">{{ $label }}</x-filament::button>
                            @endforeach
                        </div>
                        @if ($period === 'custom')
                            <div class="tpz-dashboard-custom-period">
                                <label>From <x-filament::input.wrapper><x-filament::input type="date" wire:model="customFrom" /></x-filament::input.wrapper></label>
                                <label>To <x-filament::input.wrapper><x-filament::input type="date" wire:model="customTo" /></x-filament::input.wrapper></label>
                                <x-filament::button type="button" size="xs" wire:click="applyCustomRange">Apply</x-filament::button>
                                @error('customFrom') <span class="tpz-dashboard-period-error">{{ $message }}</span> @enderror
                                @error('customTo') <span class="tpz-dashboard-period-error">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>
                </div>

                <div class="tpz-home-kpis">
                    @forelse($reference_kpis as $kpi)
                        @php
                            $icon = ($kpi['label'] === 'Total Returns')
                                ? 'heroicon-o-chart-bar-square'
                                : match($kpi['tone']) {
                                'blue' => 'heroicon-o-shopping-bag',
                                'green' => 'heroicon-o-globe-alt',
                                'amber' => 'heroicon-o-clipboard-document-list',
                                'indigo' => 'heroicon-o-arrows-right-left',
                                default => 'heroicon-o-arrow-uturn-left',
                            };
                        @endphp
                        <a href="{{ $kpi['url'] }}" class="tpz-home-kpi {{ $kpi['tone'] }}" aria-label="{{ $kpi['label'] }}: {{ number_format($kpi['value']) }}">
                            <x-filament::icon :icon="$icon" class="size-5" />
                            <div class="tpz-native-kpi-copy">
                                <span>{{ $kpi['label'] }}</span>
                                <strong>{{ number_format($kpi['value']) }}</strong>
                            </div>
                            @if(isset($kpi['trend']))
                                <span
                                    class="tpz-native-kpi-trend is-{{ $kpi['trend']['direction'] }}"
                                    title="{{ $kpi['trend']['title'] }}. The large number is the total currently visible."
                                    aria-label="{{ $kpi['trend']['title'] }}">
                                    {{ $kpi['trend']['direction'] === 'up' ? '↑' : ($kpi['trend']['direction'] === 'down' ? '↓' : '→') }}
                                    {{ $kpi['trend']['label'] }}
                                </span>
                            @endif
                        </a>
                    @empty
                        <p class="tpz-empty">No KPI cards are available for your permissions.</p>
                    @endforelse
                </div>

                <div class="tpz-home-panels">

                    <x-filament::section compact heading="My Workspace">
                        <div class="tpz-home-list">
                            @forelse ($attention->take(5) as $item)
                                <a href="{{ $item['url'] }}">
                                    @php
                                        $workLabel = strtolower((string) $item['label']);
                                        $workIcon = match (true) {
                                            str_contains($workLabel, 'approval') => 'heroicon-o-check-badge',
                                            str_contains($workLabel, 'request') => 'heroicon-o-clipboard-document-list',
                                            str_contains($workLabel, 'transfer') => 'heroicon-o-arrows-right-left',
                                            str_contains($workLabel, 'order') => 'heroicon-o-shopping-bag',
                                            str_contains($workLabel, 'task') => 'heroicon-o-clipboard-document-check',
                                            str_contains($workLabel, 'qc') => 'heroicon-o-magnifying-glass-circle',
                                            str_contains($workLabel, 'claim') => 'heroicon-o-shield-check',
                                            default => 'heroicon-o-bell-alert',
                                        };
                                    @endphp
                                    <x-filament::icon :icon="$workIcon" class="tpz-home-row-icon" />
                                    <span class="tpz-home-row-label">{{ $item['label'] }}</span>
                                    <b>{{ number_format($item['count']) }}</b>
                                </a>
                            @empty
                                <div class="tpz-empty">No pending work.</div>
                            @endforelse
                        </div>
                    </x-filament::section>

                    <x-filament::section compact heading="Recent Activity">
                        @if($recent_activity['enabled'])
                            <x-slot name="afterHeader">
                                <a href="{{ \App\Filament\Resources\StockMovements\StockMovementResource::getUrl() }}" class="tpz-native-view-all">View all</a>
                            </x-slot>
                        @endif
                        <div class="tpz-home-list tpz-home-activity">
                            @if ($recent_activity['enabled'])
                                @forelse ($recent_activity['movements']->take(5) as $movement)
                                    @php
                                        $movementType = strtolower((string) ($movement->movement_type?->value ?? $movement->movement_type?->label() ?? 'movement'));
                                        $activityIcon = match (true) {
                                            str_contains($movementType, 'transfer') => 'heroicon-o-arrows-right-left',
                                            str_contains($movementType, 'request') => 'heroicon-o-clipboard-document-check',
                                            str_contains($movementType, 'adjust') => 'heroicon-o-adjustments-horizontal',
                                            default => 'heroicon-o-cube',
                                        };
                                        $activityTime = $movement->occurred_at
                                            ? app(\App\Services\UserTimezoneResolver::class)->date($movement->occurred_at, auth()->user())
                                            : null;
                                    @endphp
                                    <div class="tpz-list-row">
                                        <span class="tpz-home-activity-icon"><x-filament::icon :icon="$activityIcon" class="size-4" /></span>
                                        <span class="tpz-home-row-label">
                                            <strong>{{ $movement->product?->sku ?? $movement->reference }}</strong>
                                            <small>{{ $movement->movement_type?->label() ?? 'Inventory movement' }}</small>
                                        </span>
                                        <time datetime="{{ $activityTime?->toIso8601String() }}" title="{{ $activityTime?->format('d M Y H:i T') }}">{{ $activityTime?->diffForHumans() ?? '—' }}</time>
                                    </div>
                                @empty
                                    <div class="tpz-empty">No recent inventory activity.</div>
                                @endforelse
                            @else
                                <div class="tpz-empty">No authorized activity available.</div>
                            @endif
                        </div>
                    </x-filament::section>

                    <x-filament::section compact heading="Quick Actions">
                        <div class="tpz-home-actions">
                            @forelse ($quick_actions as $action)
                                <a href="{{ $action['url'] }}">
                                    <x-filament::icon :icon="$action['icon']" class="tpz-native-action-icon size-4" />
                                    <span>{{ $action['label'] }}</span>
                                </a>
                            @empty
                                <div class="tpz-empty">No authorized actions available.</div>
                            @endforelse
                        </div>
                    </x-filament::section>

                </div>
                @if (! empty($warehouse_breakdown))
                    <x-filament::section compact heading="Inventory & Warehouse" description="Stock position, valuation and operational inventory status.">
                        <div class="tpz-native-detail-card-grid tpz-dashboard-inventory-unified" aria-label="Inventory metrics and warehouses">
                            @foreach (['inventory_units', 'sellable_inventory', 'inventory_value', 'low_stock', 'out_of_stock', 'damaged', 'qc_pending', 'marketplace_inventory'] as $inventoryKey)
                                @if ($card = $cardsByKey->get($inventoryKey))
                                    @include('filament.widgets.dashboard.native-metric', ['card' => $card, 'icons' => $icons])
                                @endif
                            @endforeach
                            @include('filament.widgets.dashboard.warehouse-overview')
                        </div>
                    </x-filament::section>
                @endif

                @include('filament.widgets.dashboard.native-operational-sections')
                @if(in_array('inventory_intelligence', $visible_widget_keys, true) && $inventory_intelligence !== null)
                    <div class="tpz-native-intelligence" aria-label="Inventory Intelligence">
                        @include('filament.widgets.dashboard.inventory-intelligence')
                    </div>
                @endif
            </div>
        @endif
        @unless($nativeMode)
        <x-filament::section compact>
            <x-slot name="heading">Operational Overview</x-slot>
            <x-slot name="description">{{ $period_label }} · {{ $role_label }} access</x-slot>
            <x-slot name="afterHeader">
                <div class="erp-dashboard-header-actions">
                    <div class="erp-dashboard-period" role="tablist" aria-label="Dashboard period">
                        @foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'custom' => 'Custom'] as $key => $label)
                            <x-filament::button type="button" size="xs" :color="$period === $key ? 'primary' : 'gray'" :outlined="$period !== $key" wire:click="setPeriod('{{ $key }}')" role="tab" :aria-selected="$period === $key ? 'true' : 'false'">{{ $label }}</x-filament::button>
                        @endforeach
                    </div>
                    <x-filament::button type="button" size="xs" color="gray" icon="heroicon-m-adjustments-horizontal" wire:click="toggleCustomizeMode">{{ $customize_mode ? 'Close Customization' : 'Customize Dashboard' }}</x-filament::button>
                </div>
            </x-slot>

            @if ($period === 'custom')
                <div class="erp-dashboard-controls" data-custom-period-controls>
                    <label class="erp-dashboard-control-label">
                        <span>From Date</span>
                        <x-filament::input.wrapper :valid="! $errors->has('customFrom')"><x-filament::input type="date" wire:model="customFrom" /></x-filament::input.wrapper>
                        @error('customFrom') <span style="font-weight: 400;">{{ $message }}</span> @enderror
                    </label>
                    <label class="erp-dashboard-control-label">
                        <span>To Date</span>
                        <x-filament::input.wrapper :valid="! $errors->has('customTo')"><x-filament::input type="date" wire:model="customTo" /></x-filament::input.wrapper>
                        @error('customTo') <span style="font-weight: 400;">{{ $message }}</span> @enderror
                    </label>
                    <div><x-filament::button type="button" size="sm" wire:click="applyCustomRange">Apply</x-filament::button></div>
                </div>
            @endif
        </x-filament::section>

        @if ($customize_mode)
            <x-filament::section compact data-dashboard-customizer>
                <x-slot name="heading">Customize Dashboard</x-slot>
                <x-slot name="description">Show, hide, or reorder the widgets available for your current access. Changes save automatically.</x-slot>
                <x-slot name="afterHeader"><x-filament::button type="button" size="xs" wire:click="finishCustomizing">Done</x-filament::button></x-slot>

                <div class="erp-dashboard-customizer-group">
                    <div class="erp-dashboard-customizer-list">
                        @foreach ($available_widgets as $widget)
                            @php($isHidden = in_array($widget['key'], $hidden_widget_keys, true))
                            <div class="erp-dashboard-customizer-row" wire:key="customizer-{{ $widget['key'] }}">
                                <div style="min-width: 0;">
                                    <div class="erp-dashboard-customizer-label" title="{{ $widget['label'] }}">{{ $widget['label'] }}</div>
                                    <div class="erp-dashboard-muted">{{ $widget['category'] }}</div>
                                </div>
                                <div class="erp-dashboard-customizer-actions">
                                    <x-filament::icon-button size="sm" color="gray" icon="heroicon-m-chevron-up" label="Move {{ $widget['label'] }} earlier" wire:click="moveWidget('{{ $widget['key'] }}', 'up')" />
                                    <x-filament::icon-button size="sm" color="gray" icon="heroicon-m-chevron-down" label="Move {{ $widget['label'] }} later" wire:click="moveWidget('{{ $widget['key'] }}', 'down')" />
                                    <x-filament::button type="button" size="xs" :color="$isHidden ? 'gray' : 'success'" :outlined="$isHidden" wire:click="toggleWidget('{{ $widget['key'] }}')">{{ $isHidden ? 'Show' : 'Visible' }}</x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-top: 1rem;">
                    <x-filament::button type="button" size="sm" color="danger" outlined wire:click="resetDashboard" wire:confirm="Reset your Dashboard to the current default for your role?">Reset Dashboard</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if ($visible_widget_keys === [])
            <x-filament::section compact>
                <div class="erp-dashboard-empty-state">
                    <div class="erp-dashboard-card-label">No Dashboard widgets are currently visible.</div>
                    <div class="erp-dashboard-muted">Use Customize Dashboard to restore an available widget.</div>
                </div>
            </x-filament::section>
        @else
            <div
                class="erp-dashboard-stack"
                data-dashboard-widget-list
                x-data
                x-on:dragover.prevent="
                    const dragged = $el.querySelector('[data-dragging=true]');
                    const target = $event.target.closest('[data-dashboard-widget]');
                    if (! dragged || ! target || dragged === target) return;
                    const rect = target.getBoundingClientRect();
                    const before = $event.clientY < (rect.top + rect.height / 2);
                    target.parentNode.insertBefore(dragged, before ? target : target.nextSibling);
                "
            >
                @foreach ($visible_widget_keys as $widgetKey)
                    @php($definition = $widget_definitions->get($widgetKey))
                    @continue($definition === null)
                    <div class="erp-dashboard-widget" data-dashboard-widget="{{ $widgetKey }}" data-widget-key="{{ $widgetKey }}" data-dragging="false" wire:key="dashboard-widget-{{ $widgetKey }}">
                        @if ($customize_mode)
                            <button
                                type="button"
                                class="erp-dashboard-drag-handle"
                                draggable="true"
                                aria-label="Drag {{ $definition['label'] }}"
                                x-on:dragstart="
                                    const item = $el.closest('[data-dashboard-widget]');
                                    item.dataset.dragging = 'true';
                                    $event.dataTransfer.effectAllowed = 'move';
                                    $event.dataTransfer.setData('text/plain', item.dataset.widgetKey);
                                "
                                x-on:dragend="
                                    const item = $el.closest('[data-dashboard-widget]');
                                    item.dataset.dragging = 'false';
                                    const order = Array.from(item.parentNode.querySelectorAll('[data-dashboard-widget]')).map((element) => element.dataset.widgetKey);
                                    $wire.reorderWidgets(order);
                                "
                            >
                                <x-heroicon-m-bars-3 class="h-4 w-4" /> Move
                            </button>
                        @endif

                        @if (isset($sections[$widgetKey]))
                            @php($sectionCards = collect($sections[$widgetKey]['keys'])->map(fn ($key) => $cardsByKey->get($key))->filter()->values())
                            @if ($sectionCards->isNotEmpty())
                                <x-filament::section compact :data-dashboard-section="$widgetKey">
                                    <x-slot name="heading">{{ $sections[$widgetKey]['heading'] }}</x-slot>
                                    <x-slot name="description">{{ $sections[$widgetKey]['description'] }}</x-slot>
                                    <div class="erp-dashboard-card-grid grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                        @foreach ($sectionCards as $card)
                                            @include('filament.widgets.dashboard.metric-card', ['card' => $card, 'icons' => $icons])
                                        @endforeach
                                    </div>
                                    @if ($widgetKey === 'inventory')
                                        @if(! empty($warehouse_breakdown))
                                            <div class="tpz-classic-warehouse-breakdown"><h3 class="tpz-classic-warehouse-heading">Warehouse Stock</h3><div class="tpz-classic-warehouse-grid" aria-label="Authorized warehouse stock breakdown">
                                                @include('filament.widgets.dashboard.warehouse-overview')
                                                </div></div>
                                        @endif
                                    @endif
                                </x-filament::section>
                            @endif
                        @elseif ($widgetKey === 'inventory_intelligence')
                            @include('filament.widgets.dashboard.inventory-intelligence')
                        @elseif ($widgetKey === 'attention')
                            @include('filament.widgets.dashboard.attention')
                        @elseif ($widgetKey === 'responsibilities')
                            @include('filament.widgets.dashboard.responsibilities')
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
        @endunless
    </div>
</x-filament-widgets::widget>
