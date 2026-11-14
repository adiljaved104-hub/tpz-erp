@php
    $sections = [
        'sales' => ['heading' => 'Sales & Orders', 'description' => 'Order activity and common operational entry points.', 'keys' => ['orders', 'revenue', 'quick_purchase', 'record_return']],
        'inventory' => ['heading' => 'Inventory', 'description' => 'Current authorized stock position.', 'keys' => ['inventory_units', 'sellable_inventory', 'inventory_value', 'low_stock', 'out_of_stock', 'damaged', 'qc_pending', 'marketplace_inventory']],
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

    @if($nativeMode)
        <header class="tpz-workspace-header mb-4 flex flex-wrap items-start justify-between gap-4" aria-label="ERP home dashboard">
            <div>
                <p class="tpz-workspace-eyebrow">Tech Point Zone • Operations</p>
                <h1 class="tpz-workspace-title">Home Dashboard</h1>
                <p class="tpz-workspace-subtitle">Your authorized business overview, operational alerts and quick actions.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-white/90">
                <span class="rounded-full border border-white/25 px-3 py-1.5">{{ $period_label }}</span>
                <span class="rounded-full border border-white/25 px-3 py-1.5">{{ $role_label }} access</span>
            </div>
        </header>
    @endif

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

                <div class="tpz-home-welcome">
                    <div>
                        <div class="tpz-eyebrow">TPZ ERP</div>
                        <h1>Welcome, {{ auth()->user()->name }}</h1>
                        <p>Your authorized operational overview.</p>
                    </div>
                </div>

                <div class="tpz-home-kpis">
                    @foreach ($cards->filter(fn ($card) => ($card['type'] ?? null) !== 'action' && is_numeric($card['value'] ?? null))->take(5)->values() as $index => $card)
                        @php
                            $tone = ['blue', 'green', 'amber', 'indigo', 'rose'][$index % 5];
                        @endphp

                        <div class="tpz-home-kpi {{ $tone }}">
                            <div>
                                <span>{{ $card['label'] }}</span>
                                <strong>{{ number_format((float) $card['value']) }}</strong>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="tpz-home-panels">

                    <x-filament::section compact heading="My Workspace">
                        <div class="tpz-home-list">
                            @forelse ($attention->take(4) as $item)
                                <a href="{{ $item['url'] }}">
                                    <span>{{ $item['label'] }}</span>
                                    <b>{{ number_format($item['count']) }}</b>
                                </a>
                            @empty
                                <div class="tpz-empty">No pending work.</div>
                            @endforelse
                        </div>
                    </x-filament::section>

                    <x-filament::section compact heading="Recent Activity">
                        <div class="tpz-home-list">
                            @if ($recent_activity['enabled'])
                                @forelse ($recent_activity['movements']->take(4) as $movement)
                                    <div class="tpz-list-row">
                                        <span>
                                            {{ $movement->product?->sku ?? $movement->reference }}
                                        </span>
                                        <small>{{ $movement->occurred_at?->format('d M, H:i') }}</small>
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
                            @forelse (array_slice($quick_actions, 0, 4) as $action)
                                <a href="{{ $action['url'] }}">
                                    {{ $action['label'] }}
                                </a>
                            @empty
                                <div class="tpz-empty">No authorized actions available.</div>
                            @endforelse
                        </div>
                    </x-filament::section>

                </div>
                <div class="tpz-native-period" aria-label="Dashboard date range">
                    @foreach (['today' => 'Today', 'week' => 'Week', 'month' => 'Month', 'custom' => 'Custom'] as $key => $label)
                        <x-filament::button type="button" size="xs" :color="$period === $key ? 'primary' : 'gray'" :outlined="$period !== $key" wire:click="setPeriod('{{ $key }}')">{{ $label }}</x-filament::button>
                    @endforeach
                    @if ($period === 'custom')
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="customFrom" aria-label="Start date" /></x-filament::input.wrapper>
                        <x-filament::input.wrapper><x-filament::input type="date" wire:model="customTo" aria-label="End date" /></x-filament::input.wrapper>
                        <x-filament::button type="button" size="xs" wire:click="applyCustomRange">Apply</x-filament::button>
                        @error('customFrom') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                        @error('customTo') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                    @endif
                </div>
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
