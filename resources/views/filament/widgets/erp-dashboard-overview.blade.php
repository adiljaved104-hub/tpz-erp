@php
    $sections = [
        'sales' => ['heading' => 'Sales & Orders', 'description' => 'Order activity for the selected period.', 'keys' => ['orders', 'revenue']],
        'inventory' => ['heading' => 'Inventory', 'description' => 'Current authorized stock position.', 'keys' => ['inventory_units', 'sellable_inventory', 'inventory_value', 'low_stock', 'out_of_stock', 'damaged', 'qc_pending']],
        'service' => ['heading' => 'Service & Claims', 'description' => 'Open operational cases requiring follow-up.', 'keys' => ['returns', 'claims', 'warranty', 'internal_repairs', 'complaints']],
        'work' => ['heading' => 'Work', 'description' => 'Tasks and communication in your authorized scope.', 'keys' => ['tasks', 'chat_unread', 'notifications']],
        'hr' => ['heading' => 'HR', 'description' => 'Attendance, leave, and acknowledgment status.', 'keys' => ['attendance_present', 'attendance_late', 'attendance_absent', 'pending_leave', 'warning_acknowledgments', 'notice_acknowledgments']],
    ];
    $icons = [
        'orders' => 'heroicon-o-shopping-bag', 'revenue' => 'heroicon-o-banknotes',
        'inventory_units' => 'heroicon-o-cube', 'inventory_value' => 'heroicon-o-chart-bar-square',
        'sellable_inventory' => 'heroicon-o-archive-box', 'low_stock' => 'heroicon-o-exclamation-triangle', 'out_of_stock' => 'heroicon-o-x-circle',
        'damaged' => 'heroicon-o-exclamation-triangle', 'qc_pending' => 'heroicon-o-magnifying-glass-circle',
        'returns' => 'heroicon-o-arrow-uturn-left', 'claims' => 'heroicon-o-shield-check',
        'warranty' => 'heroicon-o-wrench-screwdriver', 'internal_repairs' => 'heroicon-o-cog-6-tooth',
        'complaints' => 'heroicon-o-megaphone', 'tasks' => 'heroicon-o-clipboard-document-check',
        'chat_unread' => 'heroicon-o-chat-bubble-left-right', 'notifications' => 'heroicon-o-bell',
        'attendance_present' => 'heroicon-o-user-group', 'attendance_late' => 'heroicon-o-clock',
        'attendance_absent' => 'heroicon-o-user-minus', 'pending_leave' => 'heroicon-o-calendar-days',
        'warning_acknowledgments' => 'heroicon-o-exclamation-circle', 'notice_acknowledgments' => 'heroicon-o-document-check',
    ];
    $cardsByKey = $cards->keyBy('key');
@endphp

<x-filament-widgets::widget>
    <style>
        .erp-dashboard-stack { display: grid; min-width: 0; max-width: 100%; gap: 1rem; }
        .erp-dashboard-stack > * { min-width: 0; max-width: 100%; }
        .erp-dashboard-header-actions, .erp-dashboard-period, .erp-dashboard-card-footer, .erp-dashboard-attention-action { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .erp-dashboard-card-grid { display: grid; min-width: 0; grid-template-columns: minmax(0, 1fr); gap: .75rem; }
        .erp-dashboard-card-grid > * { min-width: 0; }
        .erp-dashboard-card-link { display: block; min-width: 0; height: 100%; text-decoration: none; }
        .erp-dashboard-card-link > * { height: 100%; }
        .erp-dashboard-card-content { display: grid; min-height: 6.5rem; align-content: space-between; gap: .65rem; }
        .erp-dashboard-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .erp-dashboard-card-icon { width: 1.35rem; height: 1.35rem; flex: none; opacity: .7; }
        .erp-dashboard-card-value { font-size: 1.65rem; line-height: 1.1; font-weight: 700; letter-spacing: -.025em; }
        .erp-dashboard-card-label { margin-top: .25rem; font-size: .875rem; line-height: 1.25rem; font-weight: 600; }
        .erp-dashboard-card-help { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .75rem; line-height: 1rem; opacity: .65; }
        .erp-dashboard-card-open { font-size: .75rem; line-height: 1rem; font-weight: 600; opacity: .75; }
        .erp-dashboard-card-footer { justify-content: space-between; }
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
            .erp-dashboard-card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .erp-dashboard-attention-grid, .erp-dashboard-customizer-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 64rem) {
            .erp-dashboard-card-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .erp-dashboard-inventory-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .erp-dashboard-attention-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 96rem) { .erp-dashboard-card-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (max-width: 47.999rem) {
            .erp-dashboard-drag-handle { display: none; }
            .erp-dashboard-control-label { width: 100%; }
            .erp-dashboard-list-row, .erp-dashboard-attention-row { align-items: flex-start; }
            .erp-dashboard-attention-row { flex-wrap: wrap; }
            .erp-dashboard-customizer-row { align-items: flex-start; flex-direction: column; }
            .erp-dashboard-customizer-actions { width: 100%; justify-content: flex-end; }
        }
    </style>

    <div class="erp-dashboard-stack" data-dashboard-role="{{ $role_label }}" data-dashboard-customizing="{{ $customize_mode ? 'true' : 'false' }}">
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
                                    <div class="erp-dashboard-card-grid">
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
    </div>
</x-filament-widgets::widget>
