<x-filament-panels::page>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([['Entitlement', $balance->entitlement], ['Used', $balance->used], ['Pending', $balance->pending], ['Remaining', $balance->remaining], ['Available after Pending', $balance->availableAfterPending]] as [$label, $value])
            <x-filament::section compact><div class="text-2xl font-semibold">{{ number_format((float) $value, 2) }}</div><div class="text-xs text-gray-500">{{ $label }} days</div></x-filament::section>
        @endforeach
    </div>

    @if ($canRequest)
        <x-filament::section heading="Request Leave" compact>
            @if (! $scheduleConfigured)
                <div class="mb-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-300">Your Work Schedule day pattern is not configured. The ERP will not guess working days; submission will remain blocked until HR configures it.</div>
            @endif
            <form wire:submit="submitLeave" class="grid gap-4 lg:grid-cols-4">
                <label class="space-y-1 text-sm"><span class="font-medium">Leave Type</span><select wire:model="leaveTypeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select</option>@foreach ($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>@error('leaveTypeId')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">From</span><input type="date" wire:model="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('fromDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm"><span class="font-medium">To</span><input type="date" wire:model="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('toDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1 text-sm lg:col-span-4"><span class="font-medium">Reason</span><textarea wire:model="reason" rows="2" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('reason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <div class="flex justify-end lg:col-span-4"><x-filament::button type="submit">Submit Request</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section compact>
        <div class="flex flex-wrap items-end gap-3"><label class="space-y-1 text-sm"><span class="font-medium">Status</span><select wire:model.live="statusFilter" class="rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All</option>@foreach (['pending','approved','rejected','cancelled'] as $status)<option value="{{ $status }}">{{ str($status)->title() }}</option>@endforeach</select></label></div>
    </x-filament::section>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full min-w-[1000px] divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach (array_filter(['Reference', $showEmployee ? 'Employee' : null, 'Type', 'Dates', 'Working Days', 'Status', 'Submitted', 'Actions']) as $heading)<th class="whitespace-nowrap px-3 py-3 text-left font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr wire:key="leave-{{ $row->id }}">
                        <td class="whitespace-nowrap px-3 py-3 font-semibold">{{ $row->reference }}</td>
                        @if ($showEmployee)<td class="px-3 py-3">{{ $row->employee->name }}<div class="text-xs text-gray-500">{{ $row->employee->employee_id }}</div></td>@endif
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->type->name }}</td><td class="whitespace-nowrap px-3 py-3">{{ $row->from_date->format('d M') }} – {{ $row->to_date->format('d M Y') }}</td>
                        <td class="px-3 py-3 text-right">{{ number_format((float) $row->requested_working_days, 2) }}</td>
                        <td class="px-3 py-3"><x-filament::badge :color="match($row->status) {'approved'=>'success','rejected'=>'danger','pending'=>'warning',default=>'gray'}">{{ str($row->status)->title() }}</x-filament::badge></td>
                        <td class="whitespace-nowrap px-3 py-3">{{ $row->submitted_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</td>
                        <td class="whitespace-nowrap px-3 py-3"><div class="flex gap-2">
                            @if ($row->status === 'pending' && auth()->user()->employee?->id === $row->employee_id)<x-filament::button size="xs" color="gray" wire:click="cancel({{ $row->id }})">Cancel</x-filament::button>@endif
                            @if ($row->status === 'pending' && $this->canApproveRequest($row))<x-filament::button size="xs" wire:click="approve({{ $row->id }})">Approve</x-filament::button><x-filament::button size="xs" color="danger" wire:click="openReject({{ $row->id }})">Reject</x-filament::button>@endif
                        </div></td>
                    </tr>
                @empty<tr><td colspan="8" class="px-6 py-12 text-center text-gray-500">No Leave requests found.</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}

    <x-filament::modal id="reject-leave"><x-slot name="heading">Reject Leave Request</x-slot><label class="space-y-1 text-sm"><span class="font-medium">Reason *</span><textarea wire:model="rejectionReason" rows="4" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('rejectionReason')<p class="text-danger-600">{{ $message }}</p>@enderror</label><x-slot name="footerActions"><x-filament::button color="danger" wire:click="reject">Reject Leave</x-filament::button></x-slot></x-filament::modal>
</x-filament-panels::page>
