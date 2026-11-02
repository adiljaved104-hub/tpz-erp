<x-filament-panels::page class="hr-page-form">
    <div>
        <h2 class="text-xl font-semibold">Comp-Off</h2>
        <p class="text-sm text-gray-500">Sunday duty credits are separate from Annual Leave.</p>
    </div>

    <nav role="tablist" aria-label="Comp-Off sections" class="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-white/10">
        @foreach (['overview' => 'Overview', 'approvals' => 'Pending Approvals', 'history' => 'Credit History'] as $tab => $label)
            @if($tab !== 'approvals' || $canManage)
                <button type="button" role="tab" wire:click="showTab('{{ $tab }}')" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}"
                    @class(['rounded-lg px-4 py-2 text-sm font-semibold', 'bg-primary-600 text-white' => $activeTab === $tab, 'bg-gray-100 text-gray-700 dark:bg-white/5 dark:text-gray-200' => $activeTab !== $tab])>{{ $label }}</button>
            @endif
        @endforeach
    </nav>

    @if($activeTab === 'overview')
        <div role="tabpanel" class="space-y-5">
            @if($wallet)
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach (['earned' => 'Earned', 'used' => 'Used', 'reserved' => 'Reserved', 'available' => 'Available'] as $key => $label)
                        <x-filament::section compact><div class="text-sm text-gray-500">{{ $label }} credits</div><div class="text-3xl font-semibold">{{ $wallet[$key] }}</div></x-filament::section>
                    @endforeach
                </div>
                @if($wallet['legacy_unreconciled'] > 0)
                    <x-filament::section compact><p class="text-sm text-warning-700">Older Comp-Off records remain in their original history. Management must review them before treating them as wallet credits.</p></x-filament::section>
                @endif
            @endif

            <x-filament::section heading="Request Comp-Off" description="Working days use one credit each. Weekly offs and public holidays use none.">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1 text-sm"><span>From date</span><input type="date" wire:model="fromDate" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                    <label class="space-y-1 text-sm"><span>To date</span><input type="date" wire:model="toDate" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                </div>
                <label class="mt-3 block space-y-1 text-sm"><span>Reason</span><textarea wire:model="requestReason" rows="3" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></textarea></label>
                <div class="mt-4"><x-filament::button wire:click="submitRequest" wire:loading.attr="disabled">Send request</x-filament::button></div>
            </x-filament::section>

            <x-filament::section heading="My Requests">
                <label class="mb-3 block space-y-1 text-sm"><span>Reason for cancelling a pending request</span><input wire:model="cancellationReason" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse($myRequests as $request)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                            <div><strong>{{ $request->reference }}</strong><div>{{ $request->from_date->format('d M Y') }}–{{ $request->to_date->format('d M Y') }} · {{ $request->credits_required }} credit(s)</div><div class="text-gray-500">{{ ucfirst($request->status) }} · {{ $request->reason }}</div></div>
                            @if($request->status === 'pending')<x-filament::button size="xs" color="gray" wire:click="cancelRequest({{ $request->id }})" wire:confirm="Cancel this pending request? Enter a reason first.">Cancel</x-filament::button>@endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No requests yet.</p>
                    @endforelse
                </div>
            </x-filament::section>

            @if($canManage)
                <x-filament::section heading="Team Wallets" description="Only employees in your authorized scope appear here.">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($teamWallets as $teamWallet)
                            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10"><strong>{{ $teamWallet['employee']->name }}</strong> · {{ $teamWallet['employee']->employee_id }}
                                @if(! $teamWallet['employee']->status)<span class="text-gray-500"> · Inactive</span>@endif
                                <div class="mt-1">Earned {{ $teamWallet['earned'] }} · Used {{ $teamWallet['used'] }} · Reserved {{ $teamWallet['reserved'] }} · Available {{ $teamWallet['available'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>
    @elseif($activeTab === 'approvals' && $canManage)
        <div role="tabpanel" wire:poll.visible.30s class="space-y-5">
            <x-filament::section heading="Authorize Sunday duty" description="Authorize before the shift. Credits require verified attendance and a separate approval.">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1 text-sm"><span>Employee</span><select wire:model="dutyEmployeeId" class="w-full rounded-lg border-gray-300 dark:bg-white/5"><option value="">Select employee</option>@foreach($teamEmployees as $person)<option value="{{ $person->id }}">{{ $person->employee_id }} · {{ $person->name }}</option>@endforeach</select></label>
                    <label class="space-y-1 text-sm"><span>Sunday date</span><input type="date" wire:model="dutyDate" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                    <label class="space-y-1 text-sm"><span>Start Time</span><input type="time" wire:model="dutyStartTime" required class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                    <label class="space-y-1 text-sm"><span>End Time</span><input type="time" wire:model="dutyEndTime" required class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                </div>
                <p class="mt-2 text-sm text-gray-500">Choose a same-day duty of at least six hours. Default: 11:00 AM–5:00 PM.</p>
                <label class="mt-3 block space-y-1 text-sm"><span>Reason</span><textarea wire:model="dutyReason" rows="3" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></textarea></label>
                <div class="mt-4"><x-filament::button wire:click="authorizeDuty" wire:loading.attr="disabled">Authorize duty</x-filament::button></div>
            </x-filament::section>

            <x-filament::section heading="Sunday Duty Awaiting Approval" description="Eligibility refreshes automatically when attendance evidence changes.">
                <label class="mb-3 block space-y-1 text-sm"><span>Written rejection reason</span><input wire:model="decisionReason" class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                <div class="mb-3 text-right"><x-filament::button size="xs" color="gray" wire:click="$refresh">Refresh eligibility</x-filament::button></div>
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse($pendingDuties as $duty)
                        @php($eligibility = $dutyEligibility[$duty->id])
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                            <div class="font-semibold">{{ $duty->employee->name }} · {{ $duty->employee->employee_id }}</div>
                            <div>{{ $duty->duty_date->format('l, j F Y') }} · {{ \Carbon\CarbonImmutable::parse($duty->shift_start)->format('g:i A') }} – {{ \Carbon\CarbonImmutable::parse($duty->shift_end)->format('g:i A') }} · {{ $duty->reference }}</div>
                            <div class="mt-2"><x-filament::badge :color="$eligibility['ready'] ? 'success' : 'warning'">{{ $eligibility['status'] }}</x-filament::badge></div>
                            <p class="mt-1 text-gray-500">{{ $eligibility['reason'] }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($dutyTimingEditable[$duty->id])
                                    <x-filament::button size="xs" color="gray" wire:click="startEditingDuty({{ $duty->id }})">Edit Timings</x-filament::button>
                                @endif
                                <x-filament::button size="xs" wire:click="decideDuty({{ $duty->id }}, true)" :disabled="! $eligibility['ready']">Approve credit</x-filament::button>
                                <x-filament::button size="xs" color="gray" wire:click="decideDuty({{ $duty->id }}, false)">Reject</x-filament::button>
                            </div>
                            @if($editingDutyId === $duty->id)
                                <div class="mt-4 space-y-3 border-t border-gray-200 pt-3 dark:border-white/10">
                                    <p class="font-medium">Edit upcoming Sunday duty</p>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <label class="space-y-1"><span>New Start Time</span><input type="time" wire:model="editStartTime" required class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                                        <label class="space-y-1"><span>New End Time</span><input type="time" wire:model="editEndTime" required class="w-full rounded-lg border-gray-300 dark:bg-white/5"></label>
                                    </div>
                                    <label class="block space-y-1"><span>Reason for changing timings</span><textarea wire:model="editReason" rows="2" required class="w-full rounded-lg border-gray-300 dark:bg-white/5"></textarea></label>
                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::button size="xs" wire:click="saveDutyTimings" wire:loading.attr="disabled">Save Timings</x-filament::button>
                                        <x-filament::button size="xs" color="gray" wire:click="cancelEditingDuty">Cancel</x-filament::button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No duties awaiting approval.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Comp-Off Requests Awaiting Approval">
                <p class="mb-3 text-sm text-gray-500">The written rejection reason above also applies to request decisions.</p>
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse($pendingRequests as $request)
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10"><strong>{{ $request->employee->name }}</strong> · {{ $request->employee->employee_id }}
                            <div>{{ $request->from_date->format('d M Y') }}–{{ $request->to_date->format('d M Y') }} · {{ $request->credits_required }} credit(s)</div>
                            <p class="text-gray-500">{{ $request->reason }}</p>
                            <div class="mt-3 flex gap-2"><x-filament::button size="xs" wire:click="decideRequest({{ $request->id }}, true)">Approve</x-filament::button><x-filament::button size="xs" color="gray" wire:click="decideRequest({{ $request->id }}, false)">Reject</x-filament::button></div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No requests awaiting approval.</p>
                    @endforelse
                </div>
                @if($legacyCanApprove && $legacyPending->isNotEmpty())
                    <h3 class="mt-5 font-semibold">Older Comp-Off requests</h3>
                    @foreach($legacyPending as $legacy)
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm"><span>{{ $legacy->employee->name }} · {{ $legacy->employee->employee_id }} · {{ $legacy->reference }} · {{ $legacy->off_date->format('d M Y') }}</span><x-filament::button size="xs" wire:click="approveLegacy({{ $legacy->id }})" wire:confirm="Approve this older Comp-Off record under its original rules?">Approve old record</x-filament::button></div>
                    @endforeach
                @endif
            </x-filament::section>
        </div>
    @elseif($activeTab === 'history')
        <div role="tabpanel" class="space-y-5">
            <x-filament::section heading="Wallet Activity" description="Credits are recorded in an immutable ledger; released reservations are shown separately.">
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse($entries as $entry)
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                            <div class="flex flex-wrap justify-between gap-2"><strong>{{ $entry->reference }}</strong><span>{{ $entry->occurred_at?->timezone('Asia/Karachi')->format('d M Y H:i') }}</span></div>
                            <div>{{ $entry->employee?->name }} · {{ $entry->employee?->employee_id }} · {{ str($entry->kind)->replace('_', ' ')->title() }}</div>
                            <div>Earned {{ $entry->earned_delta }} · Reserved {{ $entry->reserved_delta }} · Used {{ $entry->used_delta }}</div>
                            <div class="text-gray-500">{{ $entry->reason }} · By {{ $entry->actor?->name ?? 'System user' }}@if($entry->request) · Request {{ ucfirst($entry->request->status) }}@endif</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No wallet transactions yet.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Approval History" description="Who authorized, approved, rejected or cancelled each request.">
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse($approvalEvents as $event)
                        <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                            <strong>{{ $event->duty?->reference ?? $event->request?->reference }}</strong> · {{ ucfirst($event->action) }}
                            <div>{{ $event->duty?->employee?->name ?? $event->request?->employee?->name }} · {{ $event->occurred_at?->timezone('Asia/Karachi')->format('d M Y H:i') }}</div>
                            <div class="text-gray-500">{{ $event->action === 'approved' ? 'Approved by' : 'Recorded by' }} {{ $event->actor?->name ?? 'System user' }}@if($event->reason) · {{ $event->reason }}@endif</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No decisions yet.</p>
                    @endforelse
                </div>
            </x-filament::section>

            @if($legacyRecords->isNotEmpty())
                <x-filament::section heading="Older Comp-Off Records" description="These original records have not been converted to wallet credits.">
                    <div class="grid gap-3 lg:grid-cols-2">@foreach($legacyRecords as $legacy)<div class="rounded-lg border p-3 text-sm">{{ $legacy->reference }} · {{ $legacy->employee->name }} · {{ $legacy->off_date->format('d M Y') }} · {{ ucfirst($legacy->status) }}</div>@endforeach</div>
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
