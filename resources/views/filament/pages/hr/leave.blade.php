<x-filament-panels::page>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">Annual leave balance</h2>
            <p class="mt-1 text-sm text-gray-500">Your leave allowance and request status for {{ $balanceYear }}.</p>
        </div>
        <x-filament::badge color="info">{{ $balanceYear }} leave year</x-filament::badge>
    </div>

    <section aria-label="Annual leave balance summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['Annual entitlement', $balance->entitlement, 'days', 'info'],
            ['Used', $balance->used, 'approved days', 'success'],
            ['Pending', $balance->pending, 'days awaiting decision', 'warning'],
            ['Available', $balance->availableAfterPending, 'days after pending requests', 'primary'],
        ] as [$label, $value, $caption, $color])
            <x-filament::section compact>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ $label }}</p>
                        <p class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ number_format((float) $value, 2) }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ $caption }}</p>
                    </div>
                    <x-filament::badge :color="$color">{{ $label === 'Available' ? 'Balance' : 'Annual' }}</x-filament::badge>
                </div>
            </x-filament::section>
        @endforeach
    </section>

    <p class="-mt-3 text-xs text-gray-500">Balance before pending requests: {{ number_format((float) $balance->remaining, 2) }} days. Entitlement adjustments: {{ number_format((float) $balance->adjustments, 2) }} days.</p>

    @if ($canRequest)
        <x-filament::section heading="Request Leave" description="Choose a leave type and dates. Working days are calculated by the existing HR schedule rules." icon="heroicon-o-paper-airplane">
            @if (! $scheduleConfigured)
                <div role="status" class="mb-5 flex gap-3 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/30 dark:text-warning-300">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0" />
                    <span>Your work schedule day pattern is not configured. HR must configure it before a request can be calculated and submitted.</span>
                </div>
            @endif
            <form wire:submit="submitLeave" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
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
                <label class="space-y-1.5 text-sm md:col-span-2 xl:col-span-4">
                    <span class="font-medium text-gray-700 dark:text-gray-200">Reason</span>
                    <textarea wire:model="reason" rows="3" maxlength="2000" class="w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" placeholder="Briefly explain your request"></textarea>
                    @error('reason')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                </label>
                <div class="flex justify-end md:col-span-2 xl:col-span-4">
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
                        <x-filament::badge :color="match($row->status) {'approved' => 'success', 'rejected' => 'danger', 'pending' => 'warning', default => 'gray'}">{{ str($row->status)->title() }}</x-filament::badge>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Leave type</p><p class="mt-1 text-sm font-medium">{{ $row->type->name }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Dates</p><p class="mt-1 text-sm font-medium">{{ $row->from_date->format('d M Y') }} – {{ $row->to_date->format('d M Y') }}</p></div>
                        <div><p class="text-xs font-medium uppercase tracking-wide text-gray-500">Working days</p><p class="mt-1 text-sm font-medium">{{ number_format((float) $row->requested_working_days, 2) }}</p></div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-end justify-between gap-3 border-t border-gray-100 pt-3 dark:border-white/10">
                        <div class="text-xs text-gray-500">
                            <p>Submitted {{ $row->submitted_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</p>
                            @if ($row->decided_at)<p class="mt-1">Decision recorded {{ $row->decided_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</p>@endif
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

    <x-filament::modal id="reject-leave">
        <x-slot name="heading">Reject leave request</x-slot>
        <label class="space-y-1.5 text-sm"><span class="font-medium">Reason *</span><textarea wire:model="rejectionReason" rows="4" maxlength="2000" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('rejectionReason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
        <x-slot name="footerActions"><x-filament::button color="danger" wire:click="reject">Reject leave</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
