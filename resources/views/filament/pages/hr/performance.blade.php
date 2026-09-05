<x-filament-panels::page>
    <x-filament::section compact>
        <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <label class="space-y-1 text-sm xl:col-span-2">
                <span class="font-medium">Period</span>
                <select wire:model.live="period" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                    <option value="this_week">This Week</option>
                    <option value="this_month">This Month</option>
                    <option value="last_month">Last Month</option>
                    <option value="last_30_days">Last 30 Days</option>
                    <option value="last_90_days">Last 90 Days</option>
                    <option value="custom">Custom</option>
                </select>
            </label>
            @if($period === 'custom')
                <label class="space-y-1 text-sm"><span class="font-medium">From</span><input type="date" wire:model.live="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
                <label class="space-y-1 text-sm"><span class="font-medium">To</span><input type="date" wire:model.live="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('toDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            @endif
            <div class="flex items-end text-sm text-gray-500 {{ $period === 'custom' ? 'xl:col-span-2' : 'md:col-span-1 xl:col-span-4' }}">{{ $rangeLabel }}</div>
        </div>
    </x-filament::section>

    @if($detail)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h2 class="text-xl font-semibold">{{ $detail['employee']->name }}</h2><p class="text-sm text-gray-500">{{ $detail['employee']->team?->name ?? 'No Team' }} · {{ $detail['employee']->role->value }}</p></div>
            @if($canOverview)<x-filament::button size="sm" color="gray" wire:click="showOverview">Back to Overview</x-filament::button>@endif
        </div>
        @php($tasks = $detail['tasks'])
        <x-filament::section heading="Workload" compact>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                @foreach(['pending_assigned'=>'Pending / Assigned','in_progress'=>'In Progress','waiting'=>'Waiting','awaiting_confirmation'=>'Awaiting Confirmation','currently_overdue'=>'Overdue'] as $key=>$label)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><div class="text-2xl font-semibold">{{ $tasks[$key] }}</div><div class="text-xs text-gray-500">{{ $label }}</div></div>
                @endforeach
            </div>
            @if($taskUrl)<div class="mt-3"><a href="{{ $taskUrl }}" wire:navigate class="text-sm font-medium text-primary-600">Open authorized Tasks →</a></div>@endif
        </x-filament::section>
        <x-filament::section heading="Delivery" compact>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                @foreach(['assigned'=>'Assigned in Period','confirmed_completed'=>'Confirmed Completed','completed_on_time'=>'Completed On Time','completed_late'=>'Completed Late','returned_for_rework'=>'Returned for Rework'] as $key=>$label)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><div class="text-2xl font-semibold">{{ $tasks[$key] }}</div><div class="text-xs text-gray-500">{{ $label }}</div></div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-gray-500">Timeliness uses the Employee completion-submission time, not the later confirmation time.</p>
        </x-filament::section>
        @php($attendance = $detail['attendance'])
        <x-filament::section heading="Attendance" compact>
            @if(! $attendance['has_data'])
                <div class="py-4 text-sm text-gray-500">No attendance data available.</div>
            @else
                <div class="grid grid-cols-2 gap-3 md:grid-cols-5 xl:grid-cols-10">
                    @foreach(['scheduled_working_days'=>'Scheduled Days','present'=>'Present','late'=>'Late','actual_absent'=>'Actual Absent','approved_leave'=>'Approved Leave','unpaid_leave'=>'Unpaid Leave','comp_off'=>'Comp Off','public_holiday'=>'Public Holiday','scheduled_off'=>'Scheduled Off','late_penalty'=>'Late Penalty Days'] as $key=>$label)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><div class="text-xl font-semibold">{{ $attendance[$key] }}</div><div class="text-xs text-gray-500">{{ $label }}</div></div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">Late Penalty is absence-equivalent context only and remains separate from Actual Absence. Approved Leave, Comp Off, holidays, and scheduled off-days are not negative performance.</p>
            @endif
        </x-filament::section>
        @php($ops = $detail['operations'])
        <x-filament::section heading="Operational Work" compact>
            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                <div><h3 class="mb-2 text-sm font-semibold">Claims</h3><div class="grid grid-cols-3 gap-2 text-sm"><span>Handled <strong>{{ $ops['claims_handled'] }}</strong></span><span>Needs Filing <strong>{{ $ops['claims_needs_filing'] }}</strong></span><span>Filed / Review <strong>{{ $ops['claims_filed_review'] }}</strong></span><span>Approved <strong>{{ $ops['claims_approved'] }}</strong></span><span>Rejected / Not Eligible <strong>{{ $ops['claims_rejected_not_eligible'] }}</strong></span><span>Paid <strong>{{ $ops['claims_paid'] }}</strong></span></div></div>
                <div><h3 class="mb-2 text-sm font-semibold">Warranty / Service</h3><div class="grid grid-cols-3 gap-2 text-sm"><span>Handled <strong>{{ $ops['warranty_handled'] }}</strong></span><span>Open <strong>{{ $ops['warranty_open'] }}</strong></span><span>Completed <strong>{{ $ops['warranty_completed'] }}</strong></span><span>Due Soon <strong>{{ $ops['warranty_due_soon'] }}</strong></span><span>SLA Breached <strong>{{ $ops['warranty_sla_breached'] }}</strong></span><span>Technician / Waiting <strong>{{ $ops['warranty_waiting_technician'] }}</strong></span></div></div>
                <div><h3 class="mb-2 text-sm font-semibold">Complaints</h3><div class="grid grid-cols-3 gap-2 text-sm"><span>Handled <strong>{{ $ops['complaints_handled'] }}</strong></span><span>Open <strong>{{ $ops['complaints_open'] }}</strong></span><span>Resolved <strong>{{ $ops['complaints_resolved'] }}</strong></span><span>Pending <strong>{{ $ops['complaints_pending'] }}</strong></span></div></div>
                <div><h3 class="mb-2 text-sm font-semibold">Returns / QC</h3><div class="grid grid-cols-2 gap-2 text-sm"><span>Returns Received <strong>{{ $ops['returns_received'] }}</strong></span><span>QC Completed <strong>{{ $ops['qc_completed'] }}</strong></span></div></div>
                <div><h3 class="mb-2 text-sm font-semibold">Internal Repairs</h3><div class="grid grid-cols-3 gap-2 text-sm"><span>Handled <strong>{{ $ops['repairs_handled'] }}</strong></span><span>Completed <strong>{{ $ops['repairs_completed'] }}</strong></span><span>Open / Follow-up <strong>{{ $ops['repairs_open'] }}</strong></span></div></div>
                <div><h3 class="mb-2 text-sm font-semibold">Orders</h3><div class="grid grid-cols-2 gap-2 text-sm"><span>Orders Handled <strong>{{ $ops['orders_handled'] }}</strong></span><span>Fulfilments Handled <strong>{{ $ops['fulfillments_handled'] }}</strong></span></div></div>
            </div>
            <p class="mt-4 text-xs text-gray-500">Counts use explicit assignee or actor attribution only. Marketplace and technician outcomes are factual context, not employee quality scores.</p>
        </x-filament::section>
    @else
        <x-filament::section heading="Employee Performance Overview" compact>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1080px] text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5"><tr><th class="px-3 py-2 text-left">Employee</th><th class="px-3 py-2 text-left">Team</th><th class="px-3 py-2 text-center">Active Tasks</th><th class="px-3 py-2 text-center">Completed</th><th class="px-3 py-2 text-center">On Time</th><th class="px-3 py-2 text-center">Overdue</th><th class="px-3 py-2 text-center">Rework</th><th class="px-3 py-2 text-center">Present</th><th class="px-3 py-2 text-center">Late</th><th class="px-3 py-2 text-center">Actual Absent</th><th class="px-3 py-2 text-center">Operational Cases</th><th class="px-3 py-2"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse($overview as $row)
                            @php($active = $row['tasks']['pending_assigned'] + $row['tasks']['in_progress'] + $row['tasks']['waiting'] + $row['tasks']['awaiting_confirmation'])
                            <tr><td class="px-3 py-3 font-medium">{{ $row['employee']->name }}</td><td class="px-3 py-3 text-gray-500">{{ $row['employee']->team?->name ?? '—' }}</td><td class="px-3 py-3 text-center">{{ $active }}</td><td class="px-3 py-3 text-center">{{ $row['tasks']['confirmed_completed'] }}</td><td class="px-3 py-3 text-center">{{ $row['tasks']['completed_on_time'] }}</td><td class="px-3 py-3 text-center">{{ $row['tasks']['currently_overdue'] }}</td><td class="px-3 py-3 text-center">{{ $row['tasks']['returned_for_rework'] }}</td><td class="px-3 py-3 text-center">{{ $row['attendance']['has_data'] ? $row['attendance']['present'] : '—' }}</td><td class="px-3 py-3 text-center">{{ $row['attendance']['has_data'] ? $row['attendance']['late'] : '—' }}</td><td class="px-3 py-3 text-center">{{ $row['attendance']['has_data'] ? $row['attendance']['actual_absent'] : '—' }}</td><td class="px-3 py-3 text-center">{{ $row['operations']['operational_cases'] }}</td><td class="px-3 py-3 text-right"><x-filament::button size="xs" color="gray" wire:click="selectEmployee({{ $row['employee']->id }})">View</x-filament::button></td></tr>
                        @empty<tr><td colspan="12" class="px-6 py-10 text-center text-gray-500">No authorized active Employees are available.</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
