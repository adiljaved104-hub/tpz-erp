<x-filament-panels::page>
    <x-filament::section heading="Employee joining date" :description="$employee->employee_id.' · '.$employee->name" icon="heroicon-o-identification">
        @if ($employee->joining_date_verified_at)
            <p class="mb-4 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800 dark:border-success-500/30 dark:bg-success-950/20 dark:text-success-300">Verified on {{ $employee->joining_date_verified_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}. Previous values remain in the permanent audit history.</p>
        @else
            <p class="mb-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/20 dark:text-warning-300">This joining date is unverified. It will not be used to calculate an annual leave balance until verified.</p>
        @endif
        <form wire:submit="save" class="space-y-5">
            <label class="block space-y-1.5 text-sm"><span class="font-medium">Actual original joining date</span><input type="date" wire:model.live="joiningDate" class="w-full max-w-sm rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('joiningDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            @if ($preview)
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-white/10 dark:bg-white/[.03]"><h3 class="font-semibold">Anniversary balance preview</h3><div class="mt-3 grid gap-4 sm:grid-cols-2"><div><p class="text-xs uppercase text-gray-500">Previous current period</p><p class="mt-1">{{ data_get($preview, 'old_current_period.start', 'Not verified') }} – {{ data_get($preview, 'old_current_period.end', '—') }}</p></div><div><p class="text-xs uppercase text-gray-500">Period with verified date</p><p class="mt-1">{{ data_get($preview, 'new_current_period.start', data_get($preview, 'new_current_period.error', '—')) }} – {{ data_get($preview, 'new_current_period.end', '—') }}</p><p class="mt-1 text-xs text-gray-600">Next anniversary: {{ data_get($preview, 'new_current_period.next_anniversary', 'Unavailable') }}</p></div></div>
                    @if (data_get($preview, 'new_current_balance.entitlement') !== null)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div><p class="text-xs uppercase text-gray-500">Annual entitlement</p><p class="mt-1 font-semibold">{{ $preview['new_current_balance']['entitlement'] }} days</p></div>
                            <div><p class="text-xs uppercase text-gray-500">Used leave</p><p class="mt-1 font-semibold">{{ $preview['new_current_balance']['used'] }} days</p></div>
                            <div><p class="text-xs uppercase text-gray-500">Remaining after approved leave</p><p class="mt-1 font-semibold">{{ $preview['new_current_balance']['remaining'] }} days</p></div>
                            <div><p class="text-xs uppercase text-gray-500">Pending</p><p class="mt-1 font-semibold">{{ $preview['new_current_balance']['pending'] }} days</p></div>
                            <div><p class="text-xs uppercase text-gray-500">Available after pending</p><p class="mt-1 font-semibold">{{ $preview['new_current_balance']['available_after_pending'] }} days</p></div>
                        </div>
                    @else
                        <p class="mt-3 text-warning-700 dark:text-warning-300">{{ data_get($preview, 'new_current_balance.unavailable', 'Balance is unavailable until this joining date is verified.') }}</p>
                    @endif
                    <p class="mt-3 text-xs text-gray-600">Existing approved/pending ERP leave requests: {{ $preview['existing_approved_or_pending_leave_requests'] }}. Historical records: {{ $preview['existing_approved_or_pending_historical_records'] }}. No existing leave record will be edited.</p></div>
            @endif
            <label class="block space-y-1.5 text-sm"><span class="font-medium">Mandatory reason</span><textarea rows="3" maxlength="2000" required wire:model="reason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Explain the source used to verify or correct this date"></textarea>@error('reason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" wire:model="confirmed" class="mt-1"><span>I reviewed the leave-period impact and confirm this is the employee’s actual joining date.</span></label>@error('confirmed')<p class="text-danger-600">{{ $message }}</p>@enderror
            <div class="flex justify-end gap-2"><x-filament::button color="gray" :href="\App\Filament\Resources\Employees\EmployeeResource::getUrl('edit', ['record' => $employee->id])" tag="a">Cancel</x-filament::button><x-filament::button type="submit">Verify / Correct Joining Date</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
