<x-filament-panels::page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $showEmployee ? 'Attendance' : 'My Attendance' }}</h2>
            <p class="text-sm text-gray-500">{{ $rangeLabel }}</p>
        </div>
    </div>

    @php
        $latePenalty = (float) $summary['late_penalty'];
        $latePenaltyDisplay = fmod($latePenalty, 1.0) === 0.0 ? number_format($latePenalty, 0) : number_format($latePenalty, 2);
    @endphp
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-9">
        @foreach ([['Present', $summary['present']], ['Late', $summary['late']], ['Actual Absent', $summary['absent']], ['Approved Leave', $summary['approved_leave']], ['Comp Off', $summary['comp_off']], ['Early Check-outs', $summary['early_checkout']], ['Total Early Minutes', number_format($summary['early_checkout_minutes'])], ['Missing Check-out', $summary['missing_checkout']], ['Late Penalty', $latePenaltyDisplay.' '.str('day')->plural($latePenalty)]] as [$label, $value])
            <x-filament::section compact>
                <div class="min-w-0 py-0.5">
                    <div class="text-2xl font-semibold leading-none text-gray-950 dark:text-white">{{ $value }}</div>
                    <div class="mt-1 text-xs font-medium leading-tight text-gray-500">{{ $label }}</div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section compact>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
            <label class="space-y-1 text-sm font-medium">
                <span>Period</span>
                <select wire:model.live="period" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="today">Today</option><option value="this_week">This Week</option><option value="this_month">This Month</option><option value="last_month">Last Month</option><option value="custom">Custom Range</option>
                </select>
            </label>
            @if ($period === 'custom')
                <label class="space-y-1 text-sm font-medium"><span>From</span><input type="date" wire:model.live="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
                <label class="space-y-1 text-sm font-medium"><span>To</span><input type="date" wire:model.live="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            @endif
            @if ($showEmployee)
                <label class="space-y-1 text-sm font-medium"><span>Employee</span><select wire:model.live="employeeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All Employees</option>@foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }} · {{ $employee->employee_id }}</option>@endforeach</select></label>
                <label class="space-y-1 text-sm font-medium"><span>Team</span><select wire:model.live="teamId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All Teams</option>@foreach ($teams as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            @endif
        </div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[900px] divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>
                @foreach (array_filter(['Date', $showEmployee ? 'Employee' : null, 'Status', 'First Check-in', 'Last Check-out', 'Late Minutes', 'Early Check-out', 'Source', 'Corrected', $canCorrect ? 'Action' : null]) as $heading)
                    <th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>
                @endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr wire:key="attendance-{{ $row->id }}">
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->attendance_date->format('d M Y') }}</td>
                        @if ($showEmployee)<td class="min-w-48 px-3 py-3"><div class="truncate font-medium" title="{{ $row->employee->employee_id }} · {{ $row->employee->name }}">{{ $row->employee->employee_id }} · {{ $row->employee->name }}</div></td>@endif
                        <td class="whitespace-nowrap px-3 py-3"><x-filament::badge :color="match($row->status->value) {'present' => 'success', 'late' => 'warning', 'absent' => 'danger', default => 'gray'}">{{ $statuses[$row->status->value] }}</x-filament::badge></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->first_check_in_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">@if($row->hasMissingCheckout())<x-filament::badge color="warning">Missing Check-out</x-filament::badge>@else{{ $row->last_check_out_at?->timezone($row->schedule?->timezone ?: 'Asia/Karachi')->format('h:i A') ?? '—' }}@endif</td>
                        <td class="px-3 py-3 text-right">{{ $row->late_minutes }}</td>
                        <td class="whitespace-nowrap px-3 py-3">@if($row->hasEarlyCheckout())<x-filament::badge color="warning">{{ $row->early_departure_minutes }} min</x-filament::badge>@else—@endif</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ str($row->source)->replace('_', ' ')->title() }}</td>
                        <td class="px-3 py-3">{{ $row->is_overridden ? 'Yes' : 'No' }}</td>
                        @if ($canCorrect)<td class="px-3 py-3"><x-filament::button size="xs" color="gray" wire:click="openCorrection({{ $row->id }})">Correct</x-filament::button></td>@endif
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-6 py-12 text-center text-gray-500">No Attendance records exist for this period. Historical records are not fabricated.</td></tr>
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
            <label class="space-y-1 text-sm"><span class="font-medium">First Check-in</span><input type="datetime-local" wire:model="correctionFirstCheckIn" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Last Check-out</span><input type="datetime-local" wire:model="correctionLastCheckOut" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm sm:col-span-2"><span class="font-medium">Reason *</span><textarea wire:model="correctionReason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('correctionReason')<p class="text-danger-600">{{ $message }}</p>@enderror<p class="text-xs text-gray-500">The original biometric evidence remains unchanged.</p></label>
        </div>
        <x-slot name="footerActions"><x-filament::button wire:click="saveCorrection">Save correction</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
