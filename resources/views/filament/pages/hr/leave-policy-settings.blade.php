<x-filament-panels::page class="hr-page-form hr-leave-ui">
    @php($currentPolicy = $this->currentPolicy)
    <x-filament::section heading="Current Policy" description="These are the rules that apply today. Drafts and future changes do not replace them before their start date." icon="heroicon-o-shield-check">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm"><p>Applies from <strong>{{ $currentPolicy->effective_from->format('d M Y') }}</strong>{{ $currentPolicy->effective_to ? ' through '.$currentPolicy->effective_to->format('d M Y') : '' }}</p><x-filament::badge color="success">Current Policy — Active</x-filament::badge></div>
        <dl class="hr-leave-rules">
            <div><dt>Annual Leave</dt><dd>{{ number_format((float) $currentPolicy->annual_leave_entitlement_days, 2) }} days per leave year</dd></div>
            <div><dt>Leave Year</dt><dd>{{ $currentPolicy->leave_year_basis === 'joining_anniversary' ? 'Based on Joining Date' : 'Calendar Year' }}</dd></div>
            <div><dt>Monthly Accrual</dt><dd>{{ $currentPolicy->monthly_accrual_enabled ? 'Yes' : 'No' }}</dd></div>
            <div><dt>Unused Leave Carry-Forward</dt><dd>{{ $currentPolicy->carry_forward_enabled ? 'Yes — up to '.number_format((float) $currentPolicy->maximum_carry_forward_days, 2).' days' : 'No' }}</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section heading="New Annual Leave Setup" icon="heroicon-o-clipboard-document-check">
        <div class="flex flex-wrap items-start justify-between gap-4"><div class="max-w-prose"><h3 class="font-semibold">{{ $this->annualWorkflowActive ? 'HR setup approved' : ($this->individuallyActivatedCount > 0 ? 'Individual employee activation in progress' : 'Waiting for HR verification and final activation') }}</h3><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $this->annualWorkflowActive ? 'Owner/Admin has enabled the annual leave workflow. The Current Policy above shows the rules that apply today.' : ($this->individuallyActivatedCount > 0 ? 'Owner/Admin has enabled Annual Leave for '.$this->individuallyActivatedCount.' employee(s). Others remain inactive. Each enabled employee uses a verified joining-date Leave Year; the shared policy above was not changed by individual activation.' : 'Leave Year: Based on Joining Date, once enabled. The current policy remains in place while HR checks joining dates and previous leave.') }}</p></div><x-filament::badge :color="$this->annualWorkflowActive || $this->individuallyActivatedCount > 0 ? 'success' : 'warning'">{{ $this->annualWorkflowActive ? 'Enabled' : ($this->individuallyActivatedCount > 0 ? 'Individual activation' : 'Waiting for HR Review') }}</x-filament::badge></div>
        @if (\App\Filament\Pages\Hr\HistoricalLeaveRecords::canAccess())<x-filament::button class="mt-4" color="gray" tag="a" :href="\App\Filament\Pages\Hr\HistoricalLeaveRecords::getUrl()" icon="heroicon-o-arrow-right">Open Annual Leave Setup</x-filament::button>@endif
        <p class="mt-3 text-xs text-gray-500">For employees who joined on 29 February, the anniversary is 28 February in non-leap years and 29 February in leap years.</p>
    </x-filament::section>

    <x-filament::section heading="Change Leave Rules" description="Save proposed changes as a draft. Applying new rules requires permission, a valid start date and explicit confirmation." icon="heroicon-o-document-plus">
        <form wire:submit="savePolicyVersion" class="grid gap-4 md:grid-cols-2">
            <label class="space-y-1.5 text-sm"><span class="font-medium">Annual Leave Days <span class="text-danger-600">*</span></span><input type="number" required min="0" max="366" step="0.5" wire:model="annualEntitlementDays" placeholder="Example: 12">@error('annualEntitlementDays')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="space-y-1.5 text-sm"><span class="font-medium">Start Date for New Rules <span class="text-danger-600">*</span></span><input type="date" required wire:model.live="effectiveDate">@error('effectiveDate')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10 md:col-span-2"><input type="checkbox" wire:model.live="carryForwardEnabled"><span><span class="block text-sm font-medium">Carry Unused Days to Next Year</span><span class="block text-xs text-gray-500">Off by default. This does not recover expired balances from previous years.</span></span></label>
            <details class="hr-leave-details md:col-span-2" @if ($carryForwardEnabled || $policyStatus === 'active' || $errors->hasAny(['policyStatus', 'maximumCarryForwardDays', 'carryForwardExpiryMonths', 'description'])) open @endif>
                <summary>Advanced Rule Options <span>Policy status, carry-forward limits and description</span></summary>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Policy status</span><select wire:model.live="policyStatus"><option value="draft">Draft — does not affect balances</option><option value="active">Active — replace the current rules from the start date</option></select>@error('policyStatus')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            @if ($carryForwardEnabled)
                <label class="space-y-1.5 text-sm"><span class="font-medium">Maximum carry-forward days</span><input type="number" min="0.5" max="366" step="0.5" wire:model="maximumCarryForwardDays" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('maximumCarryForwardDays')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Expiry after next leave-year start (months)</span><input type="number" min="1" max="120" wire:model="carryForwardExpiryMonths" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('carryForwardExpiryMonths')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            @endif
            <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Policy description</span><textarea rows="2" maxlength="2000" wire:model="description" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('description')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                </div>
            </details>
            @if ($impact)
                <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900 dark:border-warning-500/30 dark:bg-warning-950/20 dark:text-warning-200 md:col-span-2">
                    <p class="font-semibold">How the New Rules Affect Employees</p>
                    <p class="mt-1">{{ $impact['employees_in_mid_cycle'] }} employees are partway through their leave year on this date. Choose how the change should apply before saving.</p>
                    @if ($impact['employees_in_mid_cycle'] > 0)
                        <label class="mt-3 block space-y-1"><span class="font-medium">When Should the New Days Apply? <span class="text-danger-600">*</span></span><select wire:model="cycleTransitionDecision" class="w-full rounded-lg border-gray-300 bg-white dark:border-white/10 dark:bg-white/5"><option value="">Choose how to handle the current leave year</option><option value="apply_full_entitlement">Use the full new allowance in the current leave year</option><option value="next_anniversary">Keep the current allowance until the next leave year starts</option></select>@error('cycleTransitionDecision')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
                    @endif
                </div>
            @endif
            <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason for Change <span class="text-danger-600">*</span></span><textarea rows="3" maxlength="2000" required wire:model="reason" placeholder="Explain why the leave rules need to change"></textarea>@error('reason')<p class="text-danger-600">{{ $message }}</p>@enderror</label>
            @if ($policyStatus === 'active')
                <label class="flex items-start gap-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm dark:border-warning-500/30 dark:bg-warning-950/20 md:col-span-2"><input type="checkbox" wire:model="confirmPolicyActivation" class="mt-1"><span><span class="font-semibold">Confirm active replacement</span><span class="block text-xs text-gray-600 dark:text-gray-300">The current rules stay in place until the new start date. The new rules apply from that date only. Previous rules and balances are kept.</span></span></label>
                @error('confirmPolicyActivation')<p class="text-danger-600 md:col-span-2">{{ $message }}</p>@enderror
            @endif
            <p class="text-sm text-gray-500 md:col-span-2">Drafts do not affect allowed days or balances. To apply a Draft, confirm it separately under Previous Rule Changes. Previous balances are not automatically recalculated.</p>
            <div class="flex justify-end md:col-span-2"><x-filament::button type="submit" icon="heroicon-o-check" wire:confirm="{{ $policyStatus === 'active' ? 'Schedule this replacement as an Active policy?' : 'Save these proposed rules as a Draft?' }}">{{ $policyStatus === 'active' ? 'Schedule Active Replacement' : 'Save as Draft' }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section heading="Previous Rule Changes" description="Current, scheduled and draft rules are listed together. Open Details to see the reason and audit information." icon="heroicon-o-clock">
        <div class="mb-3 flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10"><input type="checkbox" wire:model="confirmDraftActivation" class="mt-1"><span><span class="font-medium">I confirm activating a selected Draft replacement</span><span class="block text-xs text-gray-500">The current rules stay in place until the Draft's start date. A separate confirmation is required.</span>@error('confirmDraftActivation')<span class="mt-1 block text-danger-600">{{ $message }}</span>@enderror</span></div>
        <div class="hr-leave-table-scroll"><table class="hr-leave-table"><thead><tr><th>Start Date</th><th>Leave Days</th><th>Leave Year Type</th><th>Status</th><th>Changed By</th><th>Actions</th></tr></thead><tbody>
            @foreach ($this->policyVersions as $policy)
                @php($policyState = ! $policy->status ? 'Draft' : ($policy->id === $currentPolicy->id ? 'Current' : ($policy->effective_from->isFuture() ? 'Scheduled' : 'Previous')))
                <tr><td>{{ $policy->effective_from->format('d M Y') }}@if($policy->effective_to)<span class="block text-xs text-gray-500">Until {{ $policy->effective_to->format('d M Y') }}</span>@endif</td><td>{{ number_format((float) $policy->annual_leave_entitlement_days, 2) }}</td><td>{{ $policy->leave_year_basis === 'joining_anniversary' ? 'Joining Date' : 'Calendar Year' }}</td><td><x-filament::badge :color="match($policyState) {'Current' => 'success', 'Scheduled' => 'info', default => 'gray'}">{{ $policyState }}</x-filament::badge></td><td>{{ $policy->events->sortByDesc('occurred_at')->first()?->actor?->name ?: 'System' }}</td><td><details><summary class="hr-leave-link cursor-pointer">Details</summary><p class="mt-2 text-sm">{{ $policy->policy_change_reason ?: $policy->policy_description ?: 'No additional notes.' }}</p><p class="mt-1 text-xs text-gray-500">Carry-forward: {{ $policy->carry_forward_enabled ? 'Up to '.number_format((float) $policy->maximum_carry_forward_days, 2).' days' : 'No' }}</p>@if (! $policy->status && $policy->effective_to === null)<x-filament::button class="mt-3" size="sm" color="warning" wire:click="activatePolicyVersion({{ $policy->id }})" wire:confirm="Activate this Draft as a replacement policy? The current rules stay in place until the Draft's start date." :disabled="! $confirmDraftActivation">Activate Draft</x-filament::button>@endif</details></td></tr>
            @endforeach
        </tbody></table></div><div class="mt-4">{{ $this->policyVersions->links() }}</div>
    </x-filament::section>
</x-filament-panels::page>
