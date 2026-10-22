<x-filament-panels::page class="hr-page-form hr-leave-ui">
    @if ($canRecordOnBehalf)
        <div class="flex justify-end">
            <x-filament::button icon="heroicon-o-plus" wire:click="openRecordLeave">Record Employee Leave</x-filament::button>
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Your Leave Balance</h2>
            @if (! $annualWorkflowActive)<p role="status" class="mt-2 max-w-prose text-sm text-warning-700 dark:text-warning-300">Your annual leave is not ready yet. HR must check your joining date and previous leave records.</p><p class="mt-1 text-xs font-medium text-gray-500">Provisional — Pending HR Verification</p>@endif
            @if ($balancePeriod)
                <p class="mt-1 text-sm text-gray-500">Your current leave year: {{ $balancePeriod }}.</p>
            @else
                <p class="mt-1 text-sm text-gray-500">The annual balance is unavailable until the joining date is verified.</p>
            @endif
        </div>
        @if ($balancePeriod)
            <x-filament::badge color="info">{{ $balancePeriod }}</x-filament::badge>
        @endif
    </div>

    @if ($balance)
        <section aria-label="Annual leave balance summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['Annual Leave', $balance->entitlement, 'days allowed this leave year', 'gray'],
                ['Leave Used', $balance->used, 'approved days, including previous leave', 'gray'],
                ['Waiting for Approval', $balance->pending, 'days awaiting a decision', 'warning'],
                ['Days Left', $balance->availableAfterPending, 'days after pending requests', 'primary'],
            ] as [$label, $value, $caption, $color])
                <x-filament::section compact>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
                            <p class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ number_format((float) $value, 2) }}</p>
                            <p class="mt-1 text-xs text-gray-500">{{ $caption }}</p>
                        </div>
                        <x-filament::icon :icon="$label === 'Days Left' ? 'heroicon-o-calendar-days' : 'heroicon-o-chart-bar'" class="h-5 w-5 shrink-0 text-gray-400" />
                    </div>
                </x-filament::section>
            @endforeach
        </section>

        <details class="hr-leave-details"><summary>Balance Details <span>Previous leave, ERP requests and adjustments</span></summary><div class="grid gap-3 text-sm sm:grid-cols-2"><p>Previous Leave Used: <strong>{{ number_format((float) $balance->historicalUsed, 2) }} days</strong></p><p>ERP Leave Used: <strong>{{ number_format((float) $balance->currentUsed, 2) }} days</strong></p><p>Balance Before Pending Requests: <strong>{{ number_format((float) $balance->remaining, 2) }} days</strong></p><p>Balance Adjustments: <strong>{{ number_format((float) $balance->adjustments, 2) }} days</strong></p>@if ($balancePolicy?->carry_forward_enabled)<p>Unused Days Carried Forward: <strong>{{ number_format((float) $balance->carryForward, 2) }} days</strong></p>@endif</div></details>
    @else
        <div role="status" class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/30 dark:text-warning-300">
            {{ $balanceError ?: 'Ask HR to verify your actual joining date before an Annual Leave balance is calculated.' }}
        </div>
    @endif

    @if ($canRequest)
        <x-filament::section heading="Request Leave" description="Choose a leave type and dates. Working days are calculated by the existing HR schedule rules." icon="heroicon-o-paper-airplane">
            @if (! $scheduleConfigured)
                <div role="status" class="mb-5 flex gap-3 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/30 dark:text-warning-300">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>Your workdays are not set up yet. Please ask HR to set your work schedule.</span>
                </div>
            @endif
            <form wire:submit="submitLeave" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <label class="space-y-1.5 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Leave type</span>
                    <select wire:model="leaveTypeId" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5"><option value="">Select leave type</option>@foreach ($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>
                    @error('leaveTypeId')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
                <label class="space-y-1.5 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">From date</span>
                    <input type="date" wire:model="fromDate" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                    @error('fromDate')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
                <label class="space-y-1.5 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">To date</span>
                    <input type="date" wire:model="toDate" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                    @error('toDate')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
                <label class="space-y-1.5 text-sm md:col-span-2 xl:col-span-3">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Reason</span>
                    <textarea wire:model="reason" rows="3" maxlength="2000" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" placeholder="Briefly explain your request"></textarea>
                    @error('reason')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
                <div class="flex justify-end md:col-span-2 xl:col-span-3">
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Submit leave request</x-filament::button>
                </div>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section heading="Leave requests" description="Review request dates, working-day totals, and decision progress." icon="heroicon-o-clipboard-document-list">
        <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/[.03]">
            @if ($showEmployee)
                <label class="min-w-56 flex-1 space-y-1.5 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Employee</span>
                    <select wire:model.live="employeeFilter" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                        <option value="">All visible employees</option>
                        @foreach ($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->employee_id }} · {{ $employee->name }}</option>@endforeach
                    </select>
                </label>
            @endif
            <label class="min-w-44 space-y-1.5 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">Approval status</span>
                <select wire:model.live="statusFilter" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">All statuses</option>
                    @foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status)<option value="{{ $status }}">{{ str($status)->title() }}</option>@endforeach
                </select>
            </label>
            @if ($employeeFilter !== null || $statusFilter !== '')
                <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="clearFilters">Clear filters</x-filament::button>
            @endif
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            @forelse ($rows as $row)
                <article wire:key="leave-{{ $row->id }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-white/[.03]">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-gray-950 dark:text-white">{{ $row->reference }}</p>
                            @if ($showEmployee)<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row->employee->name }} <span class="text-gray-400">· {{ $row->employee->employee_id }}</span></p>@endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($row->recorded_source === 'management')<x-filament::badge color="info">Recorded by Management</x-filament::badge>@endif
                            <x-filament::badge :color="match($row->status) {'approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', default => 'gray'}">{{ str($row->status)->title() }}</x-filament::badge>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave type</p><p class="mt-1 text-sm font-medium">{{ $row->type->name }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Dates</p><p class="mt-1 text-sm font-medium">{{ $row->from_date->format('d M Y') }} – {{ $row->to_date->format('d M Y') }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Working days</p><p class="mt-1 text-sm font-medium">{{ number_format((float) $row->requested_working_days, 2) }}</p></div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-gray-100 pt-3 dark:border-white/10">
                        <div class="text-xs text-gray-500">
                            <p>Submitted {{ $row->submitted_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</p>
                            @if ($row->recorded_source === 'management')<p class="mt-1">Recorded by Management{{ $row->recordedBy ? ' · '.$row->recordedBy->name : '' }}{{ $row->recorded_at ? ' · '.$row->recorded_at->timezone('Asia/Karachi')->format('d M Y, h:i A') : '' }}</p>@endif
                            @if ($row->recorded_source === 'management')<p class="mt-1 max-w-prose">Reason: {{ $row->reason }}</p>@endif
                            @if ($row->decided_at)<p class="mt-1">Decision recorded{{ $row->decidedBy ? ' by '.$row->decidedBy->name : '' }} · {{ $row->decided_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</p>@endif
                            @if ($row->decision_reason)<p class="mt-1 max-w-prose">{{ $row->decision_reason }}</p>@endif
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($row->status === 'pending' && auth()->user()->employee?->id === $row->employee_id)<x-filament::button size="sm" color="gray" wire:click="cancel({{ $row->id }})">Cancel request</x-filament::button>@endif
                            @if ($row->status === 'pending' && $this->canApproveRequest($row))
                                <x-filament::button size="sm" wire:click="approve({{ $row->id }})">Approve</x-filament::button>
                                <x-filament::button size="sm" color="danger" wire:click="openReject({{ $row->id }})">Reject</x-filament::button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15 lg:col-span-2">
                    <x-filament::icon icon="heroicon-o-calendar-days" class="mx-auto h-9 w-9 text-gray-400" />
                    <p class="mt-3 font-medium text-gray-800 dark:text-gray-100">No leave requests found</p>
                    <p class="mt-1 text-sm text-gray-500">Requests will appear here with their approval status and date details.</p>
                </div>
            @endforelse
        </div>
        <div class="mt-5">{{ $rows->links() }}</div>
    </x-filament::section>

    @if ($canRecordOnBehalf)
        <x-filament::modal id="record-employee-leave" width="4xl">
            <x-slot name="heading">Record Employee Leave</x-slot>
            <x-slot name="description">Record a leave request for an employee. Historical dates are allowed; existing schedule and balance rules still apply.</x-slot>

            <div class="hr-page-form space-y-5">
                <div class="space-y-2">
                    <label for="record-employee-search" class="block text-sm font-medium text-gray-700 dark:text-gray-200">Employee <span class="text-danger-600">*</span></label>
                    @if ($recordEmployeeId)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 dark:border-primary-500/30 dark:bg-primary-500/10">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $recordEmployeeSearch }}</span>
                            <x-filament::button size="sm" color="gray" wire:click="clearRecordEmployee">Change</x-filament::button>
                        </div>
                    @else
                        <input id="record-employee-search" type="search" wire:model.live.debounce.250ms="recordEmployeeSearch" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" placeholder="Search by employee name or ID">
                        <div class="max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10" role="listbox" aria-label="Employees in your authorized leave scope">
                            @forelse ($recordableEmployees as $employee)
                                <button type="button" wire:key="record-leave-employee-{{ $employee->id }}" wire:click="selectRecordEmployee({{ $employee->id }})" class="block w-full border-b border-gray-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $employee->name }}</span>
                                    <span class="ml-2 text-gray-500">{{ $employee->employee_id }}</span>
                                </button>
                            @empty
                                <p class="px-3 py-3 text-sm text-gray-500">No employees match your authorized scope.</p>
                            @endforelse
                        </div>
                    @endif
                    @error('recordEmployeeId')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-1.5 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">Leave Type <span class="text-danger-600">*</span></span>
                        <select wire:model.live="recordLeaveTypeId" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                            <option value="">Select leave type</option>
                            @foreach ($leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach
                        </select>
                        @error('recordLeaveTypeId')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    </label>
                    @if ($canRecordApproval)
                        <label class="space-y-1.5 text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-200">Approval Status</span>
                            <select wire:model="recordApprovalStatus" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                                <option value="pending">Pending Approval</option>
                                <option value="approved">Approved</option>
                            </select>
                            @error('recordApprovalStatus')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                        </label>
                    @else
                        <div class="space-y-1.5 text-sm">
                            <span class="block font-medium text-gray-700 dark:text-gray-200">Approval Status</span>
                            <div class="flex h-10 items-center"><x-filament::badge color="warning">Pending Approval</x-filament::badge></div>
                            <p class="text-xs text-gray-500">Recording leave does not grant approval authority.</p>
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-1.5 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">From Date <span class="text-danger-600">*</span></span>
                        <input type="date" wire:model.live="recordFromDate" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                        @error('recordFromDate')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    </label>
                    <label class="space-y-1.5 text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">To Date <span class="text-danger-600">*</span></span>
                        <input type="date" wire:model.live="recordToDate" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                        @error('recordToDate')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                    </label>
                </div>

                @if ($recordWorkingDays !== null)
                    <div class="grid gap-3 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2 dark:border-white/10 dark:bg-white/[.03]">
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Calculated Working Days</p><p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ number_format((float) $recordWorkingDays, 2) }} days</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Annual Leave Available After Pending</p><p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ is_numeric($recordAvailableBalance) ? number_format((float) $recordAvailableBalance, 2).' days' : ($recordAvailableBalance ?? 'Select an employee, type and dates') }}</p></div>
                        @if (is_numeric($recordAvailableBalance) && (float) $recordWorkingDays > (float) $recordAvailableBalance)
                            <p class="text-sm text-danger-600 sm:col-span-2">This request exceeds the employee’s Annual Leave available after pending requests.</p>
                        @endif
                    </div>
                @endif
                @if ($recordPreviewError)
                    <p role="alert" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-950/30 dark:text-danger-300">{{ $recordPreviewError }}</p>
                @endif

                <label class="block space-y-1.5 text-sm">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Reason <span class="text-danger-600">*</span></span>
                    <textarea wire:model="recordReason" rows="4" maxlength="2000" required class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" placeholder="Explain why management is recording this leave request"></textarea>
                    @error('recordReason')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
            </div>

            <x-slot name="footerActions">
                <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'record-employee-leave' })">Cancel</x-filament::button>
                <x-filament::button icon="heroicon-o-clipboard-document-check" wire:click="recordLeave">Record Leave</x-filament::button>
            </x-slot>
        </x-filament::modal>
    @endif

    <x-filament::modal id="reject-leave">
        <x-slot name="heading">Reject leave request</x-slot>
        <label class="space-y-1.5 text-sm"><span class="font-medium">Reason *</span><textarea wire:model="rejectionReason" rows="4" maxlength="2000" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('rejectionReason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
        <x-slot name="footerActions"><x-filament::button color="danger" wire:click="reject">Reject leave</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
