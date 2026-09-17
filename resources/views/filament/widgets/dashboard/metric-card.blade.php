@php
    $_metric = isset($card) && is_array($card) ? $card : [];
    $_metricKey = $metricKey ?? ($_metric['key'] ?? null);
    $_metricTitle = $metricTitle ?? ($_metric['label'] ?? '');
    $_metricValue = $metricValue ?? ($_metric['value'] ?? 0);
    $_metricDescription = $metricDescription ?? ($_metric['description'] ?? null);
    $_metricHref = $metricHref ?? ($_metric['url'] ?? null);
    $_metricWireClick = $metricWireClick ?? null;
    $_metricIcon = $metricIcon ?? ($icons[$_metricKey] ?? 'heroicon-o-chart-bar');
    $_metricColor = $_metric['color'] ?? null;
    $_metricBadge = $metricBadge ?? (in_array($_metricColor, ['warning', 'danger', 'success'], true)
        ? ($_metricColor === 'danger' ? 'Action' : ($_metricColor === 'warning' ? 'Monitor' : 'Current'))
        : null);
    $_metricBadgeColor = $metricBadgeColor ?? $_metricColor ?? 'gray';
    $_metricShowOpen = $metricShowOpen ?? ($_metricHref !== null);
    $_metricActive = $metricActive ?? false;
    $_metricFormattedValue = is_int($_metricValue) ? number_format($_metricValue) : $_metricValue;

    $_metricAccent = $metricAccent ?? match ($_metricKey) {
        'orders', 'low_stock' => 'amber',
        'revenue' => 'emerald',
        'inventory_units', 'sellable_inventory', 'tasks', 'notifications' => 'blue',
        'inventory_value' => 'violet',
        'out_of_stock' => 'red',
        'damaged', 'complaints', 'warning_acknowledgments' => 'rose',
        'qc_pending', 'attendance_late' => 'orange',
        'returns', 'claims' => 'indigo',
        'warranty', 'internal_repairs' => 'cyan',
        default => 'slate',
    };

    $_metricVariants = [
        'amber' => ['hover' => 'hover:border-amber-400 dark:hover:border-amber-500', 'icon' => 'bg-amber-50 text-amber-600 group-hover:bg-amber-100 group-hover:text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 dark:group-hover:bg-amber-950/70', 'active' => 'border-amber-400 ring-2 ring-amber-200 dark:border-amber-500 dark:ring-amber-900/60'],
        'emerald' => ['hover' => 'hover:border-emerald-400 dark:hover:border-emerald-500', 'icon' => 'bg-emerald-50 text-emerald-600 group-hover:bg-emerald-100 group-hover:text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400 dark:group-hover:bg-emerald-950/70', 'active' => 'border-emerald-400 ring-2 ring-emerald-200 dark:border-emerald-500 dark:ring-emerald-900/60'],
        'blue' => ['hover' => 'hover:border-blue-400 dark:hover:border-blue-500', 'icon' => 'bg-blue-50 text-blue-600 group-hover:bg-blue-100 group-hover:text-blue-700 dark:bg-blue-950/40 dark:text-blue-400 dark:group-hover:bg-blue-950/70', 'active' => 'border-blue-400 ring-2 ring-blue-200 dark:border-blue-500 dark:ring-blue-900/60'],
        'violet' => ['hover' => 'hover:border-violet-400 dark:hover:border-violet-500', 'icon' => 'bg-violet-50 text-violet-600 group-hover:bg-violet-100 group-hover:text-violet-700 dark:bg-violet-950/40 dark:text-violet-400 dark:group-hover:bg-violet-950/70', 'active' => 'border-violet-400 ring-2 ring-violet-200 dark:border-violet-500 dark:ring-violet-900/60'],
        'red' => ['hover' => 'hover:border-red-400 dark:hover:border-red-500', 'icon' => 'bg-red-50 text-red-600 group-hover:bg-red-100 group-hover:text-red-700 dark:bg-red-950/40 dark:text-red-400 dark:group-hover:bg-red-950/70', 'active' => 'border-red-400 ring-2 ring-red-200 dark:border-red-500 dark:ring-red-900/60'],
        'rose' => ['hover' => 'hover:border-rose-400 dark:hover:border-rose-500', 'icon' => 'bg-rose-50 text-rose-600 group-hover:bg-rose-100 group-hover:text-rose-700 dark:bg-rose-950/40 dark:text-rose-400 dark:group-hover:bg-rose-950/70', 'active' => 'border-rose-400 ring-2 ring-rose-200 dark:border-rose-500 dark:ring-rose-900/60'],
        'orange' => ['hover' => 'hover:border-orange-400 dark:hover:border-orange-500', 'icon' => 'bg-orange-50 text-orange-600 group-hover:bg-orange-100 group-hover:text-orange-700 dark:bg-orange-950/40 dark:text-orange-400 dark:group-hover:bg-orange-950/70', 'active' => 'border-orange-400 ring-2 ring-orange-200 dark:border-orange-500 dark:ring-orange-900/60'],
        'indigo' => ['hover' => 'hover:border-indigo-400 dark:hover:border-indigo-500', 'icon' => 'bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100 group-hover:text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400 dark:group-hover:bg-indigo-950/70', 'active' => 'border-indigo-400 ring-2 ring-indigo-200 dark:border-indigo-500 dark:ring-indigo-900/60'],
        'cyan' => ['hover' => 'hover:border-cyan-400 dark:hover:border-cyan-500', 'icon' => 'bg-cyan-50 text-cyan-600 group-hover:bg-cyan-100 group-hover:text-cyan-700 dark:bg-cyan-950/40 dark:text-cyan-400 dark:group-hover:bg-cyan-950/70', 'active' => 'border-cyan-400 ring-2 ring-cyan-200 dark:border-cyan-500 dark:ring-cyan-900/60'],
        'slate' => ['hover' => 'hover:border-slate-400 dark:hover:border-slate-500', 'icon' => 'bg-slate-50 text-slate-600 group-hover:bg-slate-100 group-hover:text-slate-700 dark:bg-slate-800/60 dark:text-slate-300 dark:group-hover:bg-slate-800', 'active' => 'border-slate-400 ring-2 ring-slate-200 dark:border-slate-500 dark:ring-slate-800'],
    ];
    $_metricVariant = $_metricVariants[$_metricAccent] ?? $_metricVariants['slate'];
    $_metricWrapperClasses = implode(' ', array_filter([
        'group block h-full min-w-0 rounded-xl border border-gray-200 bg-white p-4 text-left shadow-sm transition duration-150 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:border-white/10 dark:bg-gray-900',
        $_metricVariant['hover'],
        $_metricActive ? $_metricVariant['active'] : null,
    ]));
@endphp

@if ($_metricHref !== null)
    <a href="{{ $_metricHref }}" @if ($_metricKey) data-dashboard-card="{{ $_metricKey }}" @endif class="{{ $_metricWrapperClasses }}" aria-label="Open {{ $_metricTitle }}">
@elseif ($_metricWireClick !== null)
    <button type="button" wire:click="{{ $_metricWireClick }}" class="{{ $_metricWrapperClasses }}" @if ($_metricActive) aria-pressed="true" @endif>
@else
    <div class="{{ $_metricWrapperClasses }}">
@endif
        <div class="flex h-full min-h-32 min-w-0 flex-col justify-between gap-3">
            <div class="flex min-w-0 items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $_metricTitle }}</p>
                    <p class="mt-1 break-words text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $_metricFormattedValue }}</p>
                </div>
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg transition duration-150 {{ $_metricVariant['icon'] }}">
                    <x-filament::icon :icon="$_metricIcon" class="!size-4" />
                </span>
            </div>

            @if (filled($_metricDescription) || $_metricShowOpen || filled($_metricBadge))
                <div class="min-w-0">
                    @if (filled($_metricDescription))
                        <p class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400" title="{{ $_metricDescription }}">{{ $_metricDescription }}</p>
                    @endif
                    @if ($_metricShowOpen || filled($_metricBadge))
                        <div class="mt-2 flex min-w-0 flex-wrap items-center justify-between gap-2">
                            @if ($_metricShowOpen)<span class="text-xs font-semibold text-primary-600 dark:text-primary-400">Open →</span>@endif
                            @if (filled($_metricBadge))<x-filament::badge :color="$_metricBadgeColor">{{ $_metricBadge }}</x-filament::badge>@endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
@if ($_metricHref !== null)
    </a>
@elseif ($_metricWireClick !== null)
    </button>
@else
    </div>
@endif
