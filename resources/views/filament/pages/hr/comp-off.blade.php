<x-filament-panels::page>
    <div class="flex flex-wrap items-start justify-between gap-4"><div><h2 class="text-xl font-semibold text-gray-950 dark:text-white">Comp Off</h2><p class="mt-1 text-sm text-gray-500">Review eligibility, approvals and employee Comp Off history.</p></div>@if($canCreate)<x-filament::button icon="heroicon-o-plus" wire:click="openCreateModal">Record Sunday duty</x-filament::button>@endif</div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([['Total Comp Off', $summary['total'], 'All records'], ['Pending approvals', $summary['pending'], 'Awaiting decision'], ['Approved', $summary['approved'], 'Available to use'], ['Used', $summary['used'], 'Already consumed']] as [$label, $value, $caption])
            <x-filament::section compact><div class="text-sm font-medium text-gray-500">{{ $label }}</div><div class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div><div class="mt-1 text-xs text-gray-500">{{ $caption }}</div></x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Search history" compact>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="space-y-1 text-sm"><span class="font-medium">Search</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Reference, employee or reason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Employee</span><select wire:model.live="filterEmployeeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All visible employees</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_id }} · {{ $employee->name }}</option>@endforeach</select></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Approval status</span><select wire:model.live="statusFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All statuses</option>@foreach($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        </div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">
        <table class="w-full min-w-[780px] divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach(['Reference', 'Employee', 'Worked Sunday', 'Requested Off Date', 'Source', 'Reason', 'Approval Status', 'Action'] as $heading)<th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse($records as $record)
                    <tr wire:key="comp-off-{{ $record->id }}">
                        <td class="whitespace-nowrap px-3 py-3 font-semibold">{{ $record->reference }}</td>
                        <td class="px-3 py-3"><div class="font-medium">{{ $record->employee->name }}</div><div class="text-xs text-gray-500">{{ $record->employee->employee_id }}</div></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->earned_work_date?->format('d M Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $record->off_date->format('d M Y') }}</td>
                        <td class="px-3 py-3">{{ str($record->source)->replace('_',' ')->title() }}</td>
                        <td class="max-w-64 px-3 py-3"><span class="block truncate" title="{{ $record->reason }}">{{ $record->reason }}</span></td>
                        <td class="px-3 py-3"><x-filament::badge :color="match($record->status) {'pending' => 'warning', 'approved' => 'success', 'used' => 'info', default => 'gray'}">{{ $statuses[$record->status] ?? str($record->status)->title() }}</x-filament::badge></td>
                        <td class="px-3 py-3 text-right">@if($record->status === 'pending' && $this->canApprove($record))<x-filament::button size="xs" wire:click="approve({{ $record->id }})" wire:confirm="Approve this Comp Off request?">Approve</x-filament::button>@elseif($record->approved_by_user_id)<span class="text-xs text-gray-500">{{ $record->approved_at?->timezone('Asia/Karachi')->format('d M Y') }} approved</span>@else<span class="text-gray-400">—</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-6 py-12 text-center"><div class="font-medium text-gray-700 dark:text-gray-200">No Comp Off records match these filters.</div><p class="mt-1 text-sm text-gray-500">Qualifying scheduled Sunday attendance and required approvals are checked before a record is created.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $records->links() }}

    @if($canCreate)
        <x-filament::modal id="create-comp-off" width="lg">
            <x-slot name="heading">Record qualifying Sunday duty</x-slot>
            <p class="mb-4 text-sm text-gray-500">Eligibility requires an effective schedule that marks this Sunday as working and eligible, plus actual qualifying Attendance evidence. Approval follows the effective Leave Policy.</p>
            <div class="space-y-4">
                <label class="block space-y-1 text-sm"><span class="font-medium">Employee *</span><select wire:model="employeeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select Employee</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_id }} · {{ $employee->name }}</option>@endforeach</select>@error('employeeId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="block space-y-1 text-sm"><span class="font-medium">Worked Sunday *</span><input type="date" wire:model="earnedWorkDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('earnedWorkDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="block space-y-1 text-sm"><span class="font-medium">Requested Off Date *</span><input type="date" wire:model="offDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('offDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="block space-y-1 text-sm"><span class="font-medium">Reason *</span><textarea wire:model="reason" rows="3" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('reason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            </div>
            <x-slot name="footerActions"><x-filament::button wire:click="createFromSundayDuty">Submit for approval</x-filament::button></x-slot>
        </x-filament::modal>
    @endif
</x-filament-panels::page>
