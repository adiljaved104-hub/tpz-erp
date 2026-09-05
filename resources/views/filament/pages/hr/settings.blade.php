<x-filament-panels::page>
    <div class="rounded-lg bg-info-50 p-3 text-sm text-info-800 dark:bg-info-950/30 dark:text-info-300">
        Policy changes are prospective. Saving creates a new effective-dated version; historical Attendance and Leave interpretation is preserved.
    </div>

    <form wire:submit="savePolicy" class="space-y-4">
        <x-filament::section heading="Effective Date" compact>
            <div class="max-w-sm"><label class="space-y-1 text-sm"><span class="font-medium">New policy starts *</span><input type="date" min="{{ now()->addDay()->toDateString() }}" wire:model="effectiveFrom" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('effectiveFrom')<p class="text-danger-600">{{ $message }}</p>@enderror</label></div>
        </x-filament::section>

        <div class="grid gap-4 xl:grid-cols-2">
            <x-filament::section heading="Attendance" compact>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([['officeStartTime','Office Start','time'],['officeEndTime','Office End','time'],['graceMinutes','Grace Minutes','number'],['lateOccurrencesForPenalty','Lates per Penalty','number'],['absenceEquivalentPenaltyDays','Penalty Days','number'],['halfDayMinimumMinutes','Half-day Minimum Minutes','number']] as [$field,$label,$type])
                        <label class="space-y-1 text-sm"><span class="font-medium">{{ $label }}</span><input type="{{ $type }}" @if($type==='number') min="0" step="{{ $field === 'absenceEquivalentPenaltyDays' ? '0.01' : '1' }}" @endif wire:model="{{ $field }}" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error($field)<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">Late begins one minute after the configured grace period. Leave Half-day remains disabled independently below.</p>
            </x-filament::section>

            <x-filament::section heading="Leave & Comp Off" compact>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="space-y-1 text-sm"><span class="font-medium">Annual Leave Entitlement</span><input type="number" min="0" step="0.01" wire:model="annualLeaveEntitlementDays" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('annualLeaveEntitlementDays')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                    <label class="space-y-1 text-sm"><span class="font-medium">Leave Year</span><input value="Calendar year (Jan–Dec)" disabled class="w-full rounded-lg border-gray-300 bg-gray-50 dark:border-white/10 dark:bg-white/5"></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="managerApprovalEnabled" class="rounded border-gray-300"><span>Manager approval enabled</span></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="halfDayLeaveEnabled" class="rounded border-gray-300"><span>Half-day Leave enabled</span></label>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="compensatoryOffRequiresApproval" class="rounded border-gray-300"><span>Comp Off approval required</span></label>
                    <label class="space-y-1 text-sm"><span class="font-medium">Comp Off Expiry Days</span><input type="number" min="1" wire:model="compensatoryOffExpiryDays" placeholder="No expiry" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('compensatoryOffExpiryDays')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-3"><div><div class="font-medium">{{ $schedule?->name ?? 'Pakistan Office' }}</div><div class="text-sm text-gray-500">Timezone: {{ $schedule?->timezone ?? 'Asia/Karachi' }} · Work patterns and effective assignments are managed under Work Schedules.</div></div><x-filament::button type="submit">Create Future Policy Version</x-filament::button></div>
        </x-filament::section>
    </form>

    <x-filament::section heading="Policy History" collapsed compact>
        <div class="grid gap-4 lg:grid-cols-2">
            <div><h3 class="mb-2 font-medium">Attendance</h3>@foreach($attendanceHistory as $policy)<div class="border-b py-2 text-sm dark:border-white/10"><strong>{{ $policy->effective_from->format('d M Y') }}</strong> – {{ $policy->effective_to?->format('d M Y') ?? 'Open' }} · {{ substr($policy->office_start_time,0,5) }}–{{ substr($policy->office_end_time,0,5) }} · {{ $policy->grace_minutes }} min grace</div>@endforeach</div>
            <div><h3 class="mb-2 font-medium">Leave</h3>@foreach($leaveHistory as $policy)<div class="border-b py-2 text-sm dark:border-white/10"><strong>{{ $policy->effective_from->format('d M Y') }}</strong> – {{ $policy->effective_to?->format('d M Y') ?? 'Open' }} · {{ number_format((float)$policy->annual_leave_entitlement_days,2) }} annual days</div>@endforeach</div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
