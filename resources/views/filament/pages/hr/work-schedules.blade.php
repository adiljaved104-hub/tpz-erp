<x-filament-panels::page>
    <div>
        <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Work Schedules</h2>
        <p class="mt-1 text-sm text-gray-500">Plan weekly hours, grace periods and effective-dated employee assignments.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([['Active schedules', $activeScheduleCount], ['Employees assigned today', $assignedEmployeeCount], ['Employees without a schedule', $unassignedEmployeeCount]] as [$label, $value])
            <x-filament::section compact><div class="text-sm font-medium text-gray-500">{{ $label }}</div><div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div></x-filament::section>
        @endforeach
    </div>

    <x-filament::section :heading="$editingScheduleId ? 'Configure schedule version' : 'Create a work schedule'" compact>
        <form wire:submit="saveSchedule" class="space-y-5">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="space-y-1 text-sm"><span class="font-medium">Schedule name</span><input wire:model="name" @disabled($editingScheduleId) class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('name')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Code</span><input wire:model="code" @disabled($editingScheduleId) placeholder="STANDARD_9_5" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('code')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Timezone</span><input wire:model="timezone" @disabled($editingScheduleId) class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('timezone')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Schedule type</span><select wire:model="scheduleType" @disabled($editingScheduleId) class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="standard">Standard</option><option value="alternative_weekend">Alternative Weekend</option><option value="custom">Custom</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-medium">Rotation Weeks</span><input type="number" min="1" max="12" wire:model.live="cycleLengthWeeks" @disabled($editingScheduleId) class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('cycleLengthWeeks')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Grace period (minutes)</span><input type="number" min="0" max="240" wire:model="graceMinutes" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('graceMinutes')<p class="text-danger-600">{{ $message }}</p>@enderror<p class="text-xs text-gray-500">Independent from shift start. 09:00 + 5 min means late after 09:05.</p></label>
            </div>
            @error('schedule')<div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">{{ $message }}</div>@enderror
            @error('days')<div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">{{ $message }}</div>@enderror

            <div class="space-y-4">
                @foreach (collect($days)->groupBy('cycle_week') as $week => $weekDays)
                    <section class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
                        <div class="mb-3 flex items-center justify-between"><h3 class="font-semibold">Week {{ $week }}</h3><span class="text-xs text-gray-500">Weekly pattern</span></div>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            @foreach ($weekDays as $index => $day)
                                <article wire:key="schedule-day-{{ $index }}" class="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-white/5 dark:bg-white/[.03]">
                                    <div class="flex items-center justify-between gap-2"><span class="font-medium">{{ $weekdays[$day['weekday']] }}</span><label class="inline-flex items-center gap-2 text-xs text-gray-500"><input type="checkbox" wire:model.live="days.{{ $index }}.is_working_day" class="rounded border-gray-300"> Working</label></div>
                                    @if ($day['is_working_day'])
                                        <div class="mt-3 grid grid-cols-2 gap-2"><label class="space-y-1 text-xs"><span>Start</span><input type="time" wire:model="days.{{ $index }}.expected_start_time" class="w-full rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label><label class="space-y-1 text-xs"><span>End</span><input type="time" wire:model="days.{{ $index }}.expected_end_time" class="w-full rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label></div>
                                        <details class="mt-2"><summary class="cursor-pointer text-xs font-medium text-primary-700">Break options</summary><div class="mt-2 grid grid-cols-2 gap-2"><label class="space-y-1 text-xs"><span>Break start</span><input type="time" wire:model="days.{{ $index }}.break_start_time" class="w-full rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label><label class="space-y-1 text-xs"><span>Break end</span><input type="time" wire:model="days.{{ $index }}.break_end_time" class="w-full rounded-md border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label></div></details>
                                    @else
                                        <p class="mt-3 text-xs text-gray-500">Non-working day</p>
                                    @endif
                                    @if ($day['weekday'] === 0)
                                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" wire:model="days.{{ $index }}.earns_compensatory_off" @disabled(! $day['is_working_day']) class="rounded border-gray-300"> Sunday earns Comp Off after qualifying attendance evidence</label>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
            <div class="flex flex-wrap justify-end gap-2"><x-filament::button type="submit">{{ $editingScheduleId ? 'Save schedule version' : 'Create schedule' }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Assign Schedule" compact>
        <form wire:submit="assignSchedule" class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="space-y-1 text-sm"><span class="font-medium">Schedule</span><select wire:model="assignmentScheduleId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select schedule</option>@foreach($schedules->where('status',true) as $schedule)<option value="{{ $schedule->id }}">{{ $schedule->name }} · {{ $schedule->grace_minutes ?? $schedule->attendancePolicy?->grace_minutes ?? 0 }} min grace</option>@endforeach</select>@error('assignmentScheduleId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Assign to</span><select wire:model.live="assignmentTargetType" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="employee">Employees</option><option value="team">Team</option></select></label>
                @if($assignmentTargetType === 'team')
                    <label class="space-y-1 text-sm"><span class="font-medium">Team</span><select wire:model="assignmentTeamId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select team</option>@foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->name }}</option>@endforeach</select>@error('assignmentTeamId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                @else
                    <label class="space-y-1 text-sm"><span class="font-medium">Search employees</span><input type="search" wire:model.live.debounce.300ms="assignmentEmployeeSearch" placeholder="Name or employee code" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
                    <label class="space-y-1 text-sm"><span class="font-medium">Employee grace override</span><input type="number" min="0" max="240" wire:model="assignmentGraceMinutesOverride" placeholder="Use schedule default" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><span class="text-xs text-gray-500">Optional, effective only for the selected Employees.</span></label>
                @endif
                <label class="space-y-1 text-sm"><span class="font-medium">Effective from</span><input type="date" wire:model="assignmentEffectiveFrom" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('assignmentEffectiveFrom')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Effective to</span><input type="date" wire:model="assignmentEffectiveTo" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('assignmentEffectiveTo')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm lg:col-span-2"><span class="font-medium">Reason</span><input wire:model="assignmentReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('assignmentReason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            </div>
            @if($assignmentTargetType === 'employee')
                <div class="rounded-xl border border-gray-200 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10"><span class="text-sm font-medium">Select employees · {{ count($assignmentEmployeeIds) }} selected</span><button type="button" wire:click="selectVisibleEmployees" class="text-sm font-medium text-primary-700">Select visible</button></div>
                    <div class="grid max-h-56 gap-1 overflow-y-auto p-3 sm:grid-cols-2 lg:grid-cols-3">@forelse($employees as $employee)<label wire:key="assign-employee-{{ $employee->id }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-white/5"><input type="checkbox" wire:model="assignmentEmployeeIds" value="{{ $employee->id }}" class="rounded border-gray-300"><span>{{ $employee->name }} <span class="text-xs text-gray-500">· {{ $employee->employee_id }}</span></span></label>@empty<p class="col-span-full py-4 text-center text-sm text-gray-500">No active employees match this search.</p>@endforelse</div>
                    @error('assignmentEmployeeIds')<p class="px-3 pb-2 text-sm text-danger-600">{{ $message }}</p>@enderror
                    @error('assignmentEmployeeIds.*')<p class="px-3 pb-2 text-sm text-danger-600">{{ $message }}</p>@enderror
                </div>
            @endif
            <div class="flex justify-end"><x-filament::button type="submit">{{ $assignmentTargetType === 'employee' ? 'Assign Schedule' : 'Assign Schedule to team' }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <section class="space-y-3">
        <div><h3 class="text-lg font-semibold">Schedule library</h3><p class="text-sm text-gray-500">Existing schedules and effective assignment history are retained.</p></div>
        @forelse($schedules as $schedule)
            <x-filament::section compact>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><div class="flex flex-wrap items-center gap-2"><h4 class="font-semibold">{{ $schedule->name }}</h4><x-filament::badge :color="$schedule->status ? 'success' : 'gray'">{{ $schedule->status ? 'Active' : 'Inactive' }}</x-filament::badge></div><p class="mt-1 text-sm text-gray-500">{{ $schedule->code }} · {{ str($schedule->schedule_type)->replace('_',' ')->title() }} · {{ $schedule->cycle_length_weeks }} week rotation · {{ $schedule->timezone }} · Grace {{ $schedule->grace_minutes ?? $schedule->attendancePolicy?->grace_minutes ?? 0 }} min · {{ $schedule->current_assignments_count }} current · {{ $schedule->ended_assignments_count }} ended</p></div>
                    <div class="flex gap-2"><x-filament::button size="xs" color="gray" wire:click="editPattern({{ $schedule->id }})">Edit pattern</x-filament::button><x-filament::button size="xs" color="gray" wire:click="toggleStatus({{ $schedule->id }})">{{ $schedule->status ? 'Deactivate' : 'Activate' }}</x-filament::button></div>
                </div>
                @if($schedule->days->isNotEmpty())<div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">@foreach($schedule->days->where('cycle_week', 1)->sortBy('weekday') as $day)<div class="rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-white/[.03]"><div class="font-semibold">{{ $weekdays[$day->weekday] }}</div><div class="mt-1 text-gray-500">{{ $day->is_working_day ? substr((string)$day->expected_start_time,0,5).'–'.substr((string)$day->expected_end_time,0,5) : 'Off' }} @if($day->break_start_time) · Break {{ substr((string)$day->break_start_time,0,5) }}–{{ substr((string)$day->break_end_time,0,5) }}@endif</div></div>@endforeach</div>@endif
                @if($schedule->assignments->isNotEmpty())<div class="mt-4 border-t border-gray-100 pt-3 dark:border-white/10"><h5 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Assignment history</h5><div class="space-y-2">@foreach($schedule->assignments as $assignment)@php($ended = $assignment->effective_to?->isBefore(today()) ?? false)<div class="flex flex-wrap items-center justify-between gap-2"><div class="text-sm"><span class="font-medium">{{ $assignment->employee ? 'Employee — '.$assignment->employee->name : 'Team — '.$assignment->team?->name }}</span><span class="text-gray-500"> · {{ $assignment->effective_from->format('d M Y') }}–{{ $assignment->effective_to?->format('d M Y') ?? 'Open' }} · Grace {{ $assignment->grace_minutes_override ?? $schedule->grace_minutes ?? $schedule->attendancePolicy?->grace_minutes ?? 0 }} min{{ $assignment->grace_minutes_override !== null ? ' (employee override)' : '' }}</span></div>@if($ended)<x-filament::badge color="gray">Ended</x-filament::badge>@elseif($assignment->effective_to?->isToday())<x-filament::badge color="warning">Ends today</x-filament::badge>@else{{ ($this->endAssignmentAction)(['assignmentId' => $assignment->id]) }}@endif</div>@endforeach</div></div>@endif
            </x-filament::section>
        @empty
            <x-filament::section compact><div class="py-8 text-center text-sm text-gray-500">No work schedules have been configured yet.</div></x-filament::section>
        @endforelse
    </section>
</x-filament-panels::page>
