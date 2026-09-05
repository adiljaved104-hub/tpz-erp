<x-filament-panels::page>
    @if ($canCreate)
        <x-filament::section heading="Record Qualifying Sunday Duty" compact>
            <form wire:submit="createFromSundayDuty" class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm"><span class="font-medium">Employee</span><select wire:model="employeeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select Employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_id }} · {{ $employee->name }}</option>@endforeach</select>@error('employeeId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Worked Sunday</span><input type="date" wire:model="earnedWorkDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('earnedWorkDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Approved Off Date</span><input type="date" wire:model="offDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('offDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">Reason</span><input wire:model="reason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('reason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <div class="flex flex-wrap items-center justify-between gap-3 md:col-span-2"><p class="text-xs text-gray-500">A scheduled eligible Sunday and actual qualifying Attendance evidence are both required.</p><x-filament::button type="submit">Submit Comp Off</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[850px] divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach(['Reference','Employee','Worked Date','Off Date','Source','Status','Action'] as $heading)<th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse($records as $record)
                    <tr><td class="whitespace-nowrap px-3 py-3 font-semibold">{{ $record->reference }}</td><td class="px-3 py-3"><div class="font-medium">{{ $record->employee->name }}</div><div class="text-xs text-gray-500">{{ $record->employee->employee_id }}</div></td><td class="whitespace-nowrap px-3 py-3">{{ $record->earned_work_date?->format('d M Y') ?? '—' }}</td><td class="whitespace-nowrap px-3 py-3">{{ $record->off_date->format('d M Y') }}</td><td class="px-3 py-3">{{ str($record->source)->replace('_',' ')->title() }}</td><td class="px-3 py-3"><x-filament::badge :color="$record->status === 'approved' ? 'success' : ($record->status === 'pending' ? 'warning' : 'gray')">{{ str($record->status)->title() }}</x-filament::badge></td><td class="px-3 py-3 text-right">@if($record->status === 'pending' && $this->canApprove($record))<x-filament::button size="xs" wire:click="approve({{ $record->id }})">Approve</x-filament::button>@else<span class="text-gray-500">—</span>@endif</td></tr>
                @empty<tr><td colspan="7" class="px-6 py-10 text-center text-gray-500">No Comp Off records exist.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
