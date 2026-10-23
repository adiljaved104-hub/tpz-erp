<x-filament-panels::page class="hr-page-form hr-leave-ui">
    <x-filament::section heading="Find an Employee" description="Review leave for active and inactive employees in your permitted scope." icon="heroicon-o-users">
        <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_12rem]">
            <label class="space-y-1 text-sm"><span class="font-medium">Employee name or code</span><input type="search" wire:model.live.debounce.300ms="employeeSearch" placeholder="Search employees" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Employment</span><select wire:model.live="employmentFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="all">All employees</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Joining date</span><select wire:model.live="verificationFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="all">All verification states</option><option value="verified">Verified</option><option value="unverified">Needs verification</option></select></label>
        </div>
        <div class="hr-leave-table-scroll mt-4"><table class="hr-leave-table"><thead><tr><th>Employee</th><th>Employment</th><th>Joining Date</th><th></th></tr></thead><tbody>
            @forelse ($employees as $row)
                <tr wire:key="leave-employee-{{ $row->id }}">
                    <td><strong>{{ $row->name }}</strong><span class="block text-xs text-gray-500">{{ $row->employee_id }}</span></td>
                    <td>{{ $row->status ? 'Active' : 'Inactive' }}</td>
                    <td>{{ $row->joining_date?->format('d M Y') ?: 'Not entered' }}<span class="block text-xs text-gray-500">{{ $row->joining_date_verified_at ? 'Verified' : 'Needs HR verification' }}</span></td>
                    <td><x-filament::button type="button" size="sm" color="gray" wire:click="selectEmployee({{ $row->id }})">View Leave</x-filament::button></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-gray-500">No employees match these filters.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="mt-4">{{ $employees->links() }}</div>
    </x-filament::section>

    @if ($employee)
        <x-filament::section :heading="$employee->name.' · '.$employee->employee_id" description="Review leave and audit history here. HR corrections remain in Past Leave and joining-date pages; Owner/Admin can enable this employee after review." icon="heroicon-o-identification">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2">
                    <x-filament::badge :color="$employee->status ? 'success' : 'gray'">{{ $employee->status ? 'Active Employee' : 'Inactive Employee' }}</x-filament::badge>
                    <x-filament::badge :color="$activated ? 'success' : 'warning'">{{ $activationStatus }}</x-filament::badge>
                </div>
                <x-filament::button type="button" color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadEmployeeExcel({{ $employee->id }})">Download Employee Excel</x-filament::button>
                @if ($this->canActivateAnnualLeave() && $activationStatus === 'Ready for Activation')
                    <x-filament::button type="button" wire:click="activateSelectedEmployee" wire:confirm="Enable Annual Leave for this employee only? Other employees remain inactive.">Enable Annual Leave</x-filament::button>
                @endif
            </div>
            <p class="mt-3 text-sm">Verified joining date: <strong>{{ $employee->joining_date_verified_at ? $employee->joining_date?->format('d M Y') : 'Not yet verified' }}</strong></p>
            @if ($latestReview)
                <p class="mt-1 text-xs text-gray-500">Last HR balance review: {{ \Carbon\CarbonImmutable::parse($latestReview->reviewed_at)->format('d M Y H:i') }}{{ ! $reviewed ? ' · Review again because leave information has changed' : '' }}</p>
            @endif
            @if (! $activated)
                <p role="status" class="hr-leave-notice mt-4">These leave figures are provisional while HR completes the employee review and Owner/Admin activation. They are not an approved annual leave balance.</p>
            @endif
            @if ($balance && $cycle)
                <p class="mt-4 text-sm">Current Leave Year: <strong>{{ $cycle->start->format('d M Y') }} – {{ $cycle->end->format('d M Y') }}</strong> · Next anniversary: <strong>{{ $cycle->end->addDay()->format('d M Y') }}</strong></p>
                <div class="hr-leave-summary mt-4">
                    @foreach ([['Annual days', $balance->entitlement], ['Approved days used', $balance->used], ['Pending days', $balance->pending], ['Days left after pending', $balance->availableAfterPending]] as [$label, $value])
                        <div class="hr-leave-summary-card"><p>{{ $label }}</p><strong>{{ number_format((float) $value, 2) }}</strong></div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-xl border border-gray-200 p-4 text-sm dark:border-white/10">
                    <h3 class="font-semibold">How the balance is calculated</h3>
                    <p class="mt-2">Annual days {{ number_format((float) $balance->entitlement, 2) }} + balance adjustments {{ number_format((float) $balance->adjustments, 2) }} + carry-forward {{ number_format((float) $balance->carryForward, 2) }} − previous annual leave {{ number_format((float) $balance->historicalUsed, 2) }} − approved ERP annual leave {{ number_format((float) $balance->currentUsed, 2) }} = <strong>{{ number_format((float) $balance->remaining, 2) }} days left</strong>.</p>
                    <p class="mt-1">After pending annual leave ({{ number_format((float) $balance->pending, 2) }} days), <strong>{{ number_format((float) $balance->availableAfterPending, 2) }} days are available</strong>.</p>
                    <p class="mt-1 text-gray-500">Approved unpaid leave: {{ number_format((float) $balance->unpaidUsed, 2) }} days. Unpaid leave does not reduce annual days. Voided and rejected entries are not deducted.</p>
                </div>
            @else
                <p role="status" class="hr-leave-notice mt-4">{{ $balanceError ?: 'The current Leave Year cannot be calculated until HR verifies the joining date.' }}</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Previous Leave Entered by HR" description="All entries are retained. Only approved annual leave is deducted from the relevant Leave Year." icon="heroicon-o-archive-box">
            <div class="hr-leave-table-scroll"><table class="hr-leave-table"><thead><tr><th>Dates</th><th>Type / Days</th><th>Status</th><th>Reason and History</th></tr></thead><tbody>
                @forelse ($historicalRecords as $record)
                    <tr><td>{{ $record->from_date->format('d M Y') }} – {{ $record->to_date->format('d M Y') }}<span class="block text-xs text-gray-500">{{ $record->reference }}</span></td><td>{{ $record->type?->name }}<span class="block text-xs text-gray-500">{{ number_format((float) $record->chargeable_days, 2) }} days</span></td><td>{{ str($record->approval_status)->headline() }}</td><td><p class="max-w-lg whitespace-normal">{{ $record->reason }}</p><p class="mt-1 text-xs text-gray-500">Entered by {{ $record->recorder?->name ?: 'HR' }}</p>@if ($record->remarks)<p class="mt-1 max-w-lg whitespace-normal text-xs text-gray-500">{{ $record->remarks }}</p>@endif @if ($record->correction_of_record_id)<p class="mt-1 text-xs">Correction of record #{{ $record->correction_of_record_id }}</p>@endif @if ($record->events->isNotEmpty())<details class="mt-1"><summary class="hr-leave-link cursor-pointer text-xs">Audit history</summary>@foreach ($record->events as $event)<p class="mt-1 text-xs">{{ str($event->event_type)->headline() }} · {{ $event->actor?->name ?: 'System' }} · {{ $event->occurred_at?->format('d M Y H:i') }} — {{ $event->reason }}</p>@endforeach</details>@endif</td></tr>
                @empty
                    <tr><td colspan="4" class="text-center text-gray-500">No previous leave has been entered for this employee.</td></tr>
                @endforelse
            </tbody></table></div><div class="mt-4">{{ $historicalRecords->links() }}</div>
        </x-filament::section>

        <x-filament::section heading="ERP Leave Requests" description="Requested days are shown for each request. The balance above uses approved and pending leave days in the current Leave Year only." icon="heroicon-o-calendar-days">
            <div class="hr-leave-table-scroll"><table class="hr-leave-table"><thead><tr><th>Dates</th><th>Type / Duration</th><th>Status</th><th>Reason and History</th></tr></thead><tbody>
                @forelse ($leaveRequests as $request)
                    <tr><td>{{ $request->from_date->format('d M Y') }} – {{ $request->to_date->format('d M Y') }}<span class="block text-xs text-gray-500">{{ $request->reference }}</span></td><td>{{ $request->type?->name }}<span class="block text-xs text-gray-500">{{ number_format((float) $request->requested_working_days, 2) }} requested days</span></td><td>{{ str($request->status)->headline() }}</td><td><p class="max-w-lg whitespace-normal">{{ $request->reason }}</p>@if ($request->events->isNotEmpty())<details class="mt-1"><summary class="hr-leave-link cursor-pointer text-xs">Audit history</summary>@foreach ($request->events as $event)<p class="mt-1 text-xs">{{ str($event->event_type)->headline() }} · {{ $event->actor?->name ?: 'System' }} · {{ \Carbon\CarbonImmutable::parse($event->occurred_at)->format('d M Y H:i') }}@if ($event->reason) — {{ $event->reason }}@endif</p>@endforeach</details>@endif</td></tr>
                @empty
                    <tr><td colspan="4" class="text-center text-gray-500">No ERP leave requests for this employee.</td></tr>
                @endforelse
            </tbody></table></div><div class="mt-4">{{ $leaveRequests->links() }}</div>
        </x-filament::section>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-filament::section heading="Balance Adjustments" description="Credits and debits are included by the existing balance calculation for the relevant Leave Year." icon="heroicon-o-adjustments-horizontal">
                <div class="space-y-3 text-sm">
                    @forelse ($cycleAdjustments as $adjustment)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><strong>{{ str($adjustment->adjustment_type)->headline() }} {{ number_format((float) $adjustment->days, 2) }} days</strong><p>Leave Year starting {{ $adjustment->leave_period_start->format('d M Y') }} · {{ $adjustment->reference }}</p><p class="mt-1 whitespace-normal">{{ $adjustment->reason }}</p><p class="mt-1 text-xs text-gray-500">Recorded by {{ $adjustment->recorder?->name }} · {{ $adjustment->recorded_at?->format('d M Y H:i') }}</p></div>
                    @empty
                        <p class="text-gray-500">No Leave Year adjustments.</p>
                    @endforelse
                    @foreach ($legacyAdjustments as $adjustment)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><strong>Earlier adjustment {{ number_format((float) $adjustment->days, 2) }} days</strong><p>Recorded year {{ $adjustment->leave_year }} · {{ $adjustment->reference }}</p><p class="mt-1 whitespace-normal">{{ $adjustment->reason }}</p></div>
                    @endforeach
                </div><div class="mt-4">{{ $cycleAdjustments->links() }}</div>
            </x-filament::section>
            <x-filament::section heading="Joining Date Audit" description="Original joining dates remain in the audit history after any correction." icon="heroicon-o-clipboard-document-check">
                <div class="space-y-3 text-sm">
                    @forelse ($joiningAudits as $audit)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><strong>{{ $audit->previous_joining_date?->format('d M Y') ?: 'No earlier date' }} → {{ $audit->corrected_joining_date?->format('d M Y') }}</strong><p class="mt-1 whitespace-normal">{{ $audit->reason }}</p><p class="mt-1 text-xs text-gray-500">{{ $audit->actor?->name }} · {{ $audit->occurred_at?->format('d M Y H:i') }}</p></div>
                    @empty
                        <p class="text-gray-500">No joining-date verification has been recorded yet.</p>
                    @endforelse
                </div><div class="mt-4">{{ $joiningAudits->links() }}</div>
            </x-filament::section>
        </div>
    @else
        <x-filament::section heading="Employee Leave Details" description="Choose an employee above to see their Leave Year, previous leave, ERP requests and audit history." icon="heroicon-o-document-text"><p class="text-sm text-gray-500">No employee selected.</p></x-filament::section>
    @endif
</x-filament-panels::page>
