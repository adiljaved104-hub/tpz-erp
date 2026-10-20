<x-filament-panels::page class="hr-page-form">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">{{ $showEmployee ? 'Attendance Management' : 'My Attendance' }}</h2>
            <p class="mt-1 text-sm text-gray-500">Today’s team view and audited attendance history · {{ $rangeLabel }}</p>
        </div>
        @if ($canManual)
            <x-filament::button icon="heroicon-o-plus" wire:click="openManualAttendance">Record Manual Attendance</x-filament::button>
        @endif
    </div>

    <section aria-label="Today's attendance summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Present', $summary['present'], 'success'], ['Late', $summary['late'], 'warning'], ['Absent', $summary['absent'], 'danger'], ['Half-Day', $summary['half_day'], 'info']] as [$label, $value, $color])
            <x-filament::section compact>
                <div class="flex items-center justify-between gap-3">
                    <div><p class="text-sm font-medium text-gray-500">{{ $label }}</p><p class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p></div>
                    <x-filament::badge :color="$color">Today</x-filament::badge>
                </div>
            </x-filament::section>
        @endforeach
    </section>

    <x-filament::section :heading="'Attendance calendar · '.$calendarMonthLabel" compact>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <x-filament::button size="xs" color="gray" wire:click="previousCalendarMonth">Previous month</x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="nextCalendarMonth">Next month</x-filament::button>
            </div>
            <label class="sr-only" for="attendance-calendar-month">Calendar month</label>
            <input id="attendance-calendar-month" type="month" wire:model.live="calendarMonth" class="w-auto text-sm">
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[10px] font-semibold text-gray-500 sm:gap-1.5 sm:text-xs">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)<div class="px-0.5 py-2 sm:px-1">{{ $weekday }}</div>@endforeach
        </div>
        <div class="grid grid-cols-7 gap-1 sm:gap-1.5">
            @foreach ($calendarDays as $cell)
                <div wire:key="attendance-calendar-{{ $cell['date']->toDateString() }}" class="min-h-16 min-w-0 rounded-lg border p-1 sm:min-h-24 sm:p-1.5 {{ $cell['in_month'] ? 'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' : 'border-transparent bg-gray-50/70 text-gray-400 dark:bg-white/[.02]' }}">
                    <div class="text-xs font-bold sm:text-sm">{{ $cell['date']->day }}</div>
                    <div class="mt-1 space-y-1">
                        @foreach ($cell['records'] as $calendarRecord)
                            <div class="flex min-w-0 items-center gap-1 truncate text-[9px] leading-tight sm:text-[10px]" title="{{ $calendarRecord->employee->name }} · {{ $statuses[$calendarRecord->status->value] }}">
                                <span aria-hidden="true" class="inline-block size-1.5 shrink-0 rounded-full {{ match($calendarRecord->status->value) {'present' => 'bg-emerald-500', 'late' => 'bg-amber-500', 'absent' => 'bg-red-500', 'half_day' => 'bg-blue-500', default => 'bg-gray-400'} }}"></span>
                                <span class="truncate font-medium">{{ $calendarRecord->employee->name }}</span>
                                <span class="hidden shrink-0 text-gray-500 sm:inline">· {{ str($calendarRecord->status->value)->replace('_', ' ')->title() }}</span>
                            </div>
                        @endforeach
                        @if ($cell['more_count'] > 0)<div class="text-[10px] text-gray-500">+{{ $cell['more_count'] }} more</div>@endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($calendarRecords->isEmpty())<p class="mt-3 text-center text-sm text-gray-500">No attendance records in this month. Missing records are not silently fabricated.</p>@endif
    </x-filament::section>

    <x-filament::section heading="Search and filters" compact>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            <label class="space-y-1 text-sm font-medium"><span>Period</span><select wire:model.live="period" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="today">Today</option><option value="this_week">This Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="custom">Custom Range</option></select></label>
            <label class="space-y-1 text-sm font-medium"><span>Specific date</span><input type="date" wire:model.live="dateFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm font-medium"><span>Status</span><select wire:model.live="statusFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All statuses</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            @if ($period === 'custom')
                <label class="space-y-1 text-sm font-medium"><span>From</span><input type="date" wire:model.live="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
                <label class="space-y-1 text-sm font-medium"><span>To</span><input type="date" wire:model.live="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            @endif
            @if ($showEmployee)
                <label class="space-y-1 text-sm font-medium"><span>Search employee</span><input type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Name or employee code" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
                <label class="space-y-1 text-sm font-medium"><span>Employee</span><select wire:model.live="employeeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All visible employees</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_id }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm font-medium"><span>Team</span><select wire:model.live="teamId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All visible teams</option>@foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500">Correction permission is checked for each Employee and every submission; filters do not expand your HR visibility scope.</p>
    </x-filament::section>

    @php($columnCount = 9 + ($showEmployee ? 1 : 0) + ($canCorrect ? 1 : 0))
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
        <table class="w-full min-w-[1050px] divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                @foreach (array_filter(['Date', $showEmployee ? 'Employee' : null, 'Status', 'Expected hours / grace', 'Check-in', 'Check-out', 'Late', 'Early checkout', 'Source', 'Corrected', $canCorrect ? 'Action' : null]) as $heading)<th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>@endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr wire:key="attendance-{{ $row->id }}">
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->attendance_date->format('d M Y') }}</td>
                        @if ($showEmployee)<td class="min-w-48 px-3 py-3"><div class="truncate font-medium" title="{{ $row->employee->employee_id }} · {{ $row->employee->name }}">{{ $row->employee->employee_id }} · {{ $row->employee->name }}</div></td>@endif
                        <td class="whitespace-nowrap px-3 py-3"><x-filament::badge :color="match($row->status->value) {'present' => 'success', 'late' => 'warning', 'absent' => 'danger', 'half_day' => 'info', default => 'gray'}">{{ $statuses[$row->status->value] }}</x-filament::badge></td>
                        <td class="whitespace-nowrap px-3 py-3"><div>{{ $row->expected_start_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}–{{ $row->expected_end_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}</div><div class="text-xs text-gray-500">Grace {{ $row->grace_minutes ?? $row->policy?->grace_minutes ?? 0 }} min</div></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->first_check_in_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">@if($row->hasMissingCheckout())<x-filament::badge color="warning">Missing Check-out</x-filament::badge>@else{{ $row->last_check_out_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}@endif</td>
                        <td class="px-3 py-3 text-right">{{ $row->late_minutes }} min</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->hasEarlyCheckout() ? $row->early_departure_minutes.' min' : '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ str($row->source)->replace('_', ' ')->title() }}</td>
                        <td class="px-3 py-3">{{ $row->is_overridden ? 'Yes' : 'No' }}</td>
                        @if ($canCorrect)<td class="px-3 py-3"><x-filament::button size="xs" color="gray" wire:click="openCorrection({{ $row->id }})">Correct</x-filament::button></td>@endif
                    </tr>
                    @if ($row->corrections->isNotEmpty())
                        <tr wire:key="attendance-history-{{ $row->id }}"><td colspan="{{ $columnCount }}" class="bg-gray-50 px-4 py-2 dark:bg-white/[.03]"><details><summary class="cursor-pointer text-xs font-semibold text-primary-700">Correction history · {{ $row->corrections->count() }}</summary><div class="mt-2 space-y-2">@foreach($row->corrections as $correction)<div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10"><div class="font-medium">{{ $correction->occurred_at?->timezone('Asia/Karachi')->format('d M Y, h:i A') }} · {{ $correction->actor?->name ?? 'System user' }}</div><div class="mt-1 text-gray-600 dark:text-gray-300">{{ $correction->reason }}</div><div class="mt-1 text-gray-500">{{ $correction->old_values === [] ? 'Manual entry' : 'Original values recorded' }} → {{ collect($correction->new_values)->only(['status', 'first_check_in_at', 'last_check_out_at'])->map(fn ($value, $key) => str($key)->replace('_', ' ')->title().': '.($value ?? '—'))->implode(' · ') }}</div></div>@endforeach</div></details></td></tr>
                    @endif
                @empty
                    <tr><td colspan="{{ $columnCount }}" class="px-6 py-12 text-center text-gray-500">No Attendance records exist for these filters. Historical records are not fabricated.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}

    <x-filament::modal id="correct-attendance" width="2xl">
        <x-slot name="heading">Correct Attendance</x-slot>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1 text-sm"><span class="font-medium">Status</span><select wire:model="correctionStatus" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('correctionStatus')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1 text-sm"><span class="font-medium">Late Minutes</span><input type="number" min="0" wire:model="correctionLateMinutes" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('correctionLateMinutes')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1 text-sm"><span class="font-medium">Early Check-out Minutes</span><input type="number" min="0" wire:model="correctionEarlyCheckoutMinutes" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('correctionEarlyCheckoutMinutes')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1 text-sm"><span class="font-medium">First Check-in</span><input type="datetime-local" wire:model="correctionFirstCheckIn" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('correctionFirstCheckIn')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1 text-sm"><span class="font-medium">Last Check-out</span><input type="datetime-local" wire:model="correctionLastCheckOut" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('correctionLastCheckOut')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1 text-sm sm:col-span-2"><span class="font-medium">Reason *</span><textarea wire:model="correctionReason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('correctionReason')<p class="text-danger-600">{{ $message }}</p>@enderror<p class="text-xs text-gray-500">Raw biometric events are immutable; saved corrections remain protected from later sync.</p></label>
        </div>
        <x-slot name="footerActions"><x-filament::button wire:click="saveCorrection">Save correction</x-filament::button></x-slot>
    </x-filament::modal>

    @if ($canManual)
        <x-filament::modal id="create-manual-attendance" width="2xl">
            <x-slot name="heading">Record Manual Attendance</x-slot>
            <p class="mb-4 text-sm text-gray-500">Use only when no Attendance record exists. This entry is immediately audited, marked as overridden, and will not be replaced by later biometric synchronization.</p>
            <div class="mb-4">
                <label class="block space-y-1 text-sm"><span class="font-medium">Search employees</span><input type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Search by name or employee code" class="w-full"></label>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm"><span class="font-medium">Employee *</span><select wire:model="manualEmployeeId" class="w-full"><option value="">Select visible employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_id }} · {{ $employee->name }}</option>@endforeach</select>@error('manualEmployeeId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Date *</span><input type="date" wire:model="manualAttendanceDate" class="w-full">@error('manualAttendanceDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Status *</span><select wire:model="manualStatus" class="w-full">@foreach($manualStatuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>@error('manualStatus')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Check-in</span><input type="time" wire:model="manualFirstCheckIn" class="w-full">@error('manualFirstCheckIn')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Check-out</span><input type="time" wire:model="manualLastCheckOut" class="w-full">@error('manualLastCheckOut')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm sm:col-span-2"><span class="font-medium">Reason *</span><textarea wire:model="manualReason" rows="3" placeholder="Example: Biometric device was not working, attendance verified by management."></textarea>@error('manualReason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            </div>
            <x-slot name="footerActions"><x-filament::button wire:click="saveManualAttendance">Record Attendance</x-filament::button></x-slot>
        </x-filament::modal>
    @endif
</x-filament-panels::page>
