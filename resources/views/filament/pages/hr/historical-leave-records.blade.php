<x-filament-panels::page>
    @if ($this->canRecord())
        <x-filament::section heading="Record historical leave" description="Use verified source records. Preview is read-only; no leave record is written until you confirm below.">
            <form wire:submit="recordHistoricalLeave" class="grid gap-4 md:grid-cols-2">
                <div class="space-y-2 md:col-span-2">
                    <label for="historical-employee" class="text-sm font-medium">Employee</label>
                    <input id="historical-employee" type="search" wire:model.live.debounce.300ms="employeeSearch" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Search by employee code or name">
                    @error('employeeId') <p class="text-sm text-danger-600">{{ $message }}</p> @enderror
                    @if ($employeeId === null && $employeeSearch !== '')
                        <div class="max-h-48 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10">
                            @forelse ($this->employees() as $employee)
                                <button type="button" wire:click="selectEmployee({{ $employee->id }})" class="block w-full border-b border-gray-100 px-3 py-2 text-left text-sm last:border-b-0 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                                    {{ $employee->employee_id }} · {{ $employee->name }}
                                </button>
                            @empty
                                <p class="p-3 text-sm text-gray-500">No eligible employees found in your scope.</p>
                            @endforelse
                        </div>
                    @endif
                </div>

                <label class="space-y-1.5 text-sm"><span class="font-medium">Leave type</span>
                    <select wire:model.live="leaveTypeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select leave type</option>@foreach ($this->leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>
                    @error('leaveTypeId') <p class="text-danger-600">{{ $message }}</p> @enderror
                </label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Approval status</span>
                    <select wire:model.live="approvalStatus" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="pending">Pending approval</option>@if ($this->canApprove())<option value="approved">Approved (requires approval permission)</option>@endif</select>
                    @error('approvalStatus') <p class="text-danger-600">{{ $message }}</p> @enderror
                </label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">From date</span><input type="date" wire:model.live="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('fromDate') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">To date</span><input type="date" wire:model.live="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('toDate') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Verified chargeable days (only when no schedule is available)</span><input type="number" min="0.5" max="366" step="0.5" wire:model.live="chargeableDays" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Calculated from the effective schedule when available">@error('chargeableDays') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Original reference</span><input type="text" maxlength="255" wire:model="originalReference" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Reference from the prior register or system">@error('originalReference') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                @if ($chargeableDays !== '')
                    <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Justification for verified day count</span><textarea rows="2" maxlength="2000" wire:model="manualOverrideReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('manualOverrideReason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                @endif
                <label class="space-y-1.5 text-sm"><span class="font-medium">Historical source</span><input type="text" maxlength="120" wire:model="historicalSource" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Example: signed leave register, legacy HR system">@error('historicalSource') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Original approver (if recorded)</span><input type="text" maxlength="255" wire:model="originalApprover" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('originalApprover') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Original approval date (if recorded)</span><input type="date" wire:model="originalApprovedAt" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('originalApprovedAt') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason</span><textarea rows="3" required maxlength="2000" wire:model="reason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('reason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Additional remarks</span><textarea rows="2" maxlength="10000" wire:model="remarks" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('remarks') <p class="text-danger-600">{{ $message }}</p> @enderror</label>

                @if ($preview)
                    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm dark:border-primary-500/30 dark:bg-primary-950/20 md:col-span-2">
                        <p class="font-semibold">Read-only preview</p>
                        <p class="mt-1">{{ $preview['employee_code'] }} · {{ $preview['employee_name'] }} · {{ $preview['leave_type'] }} · {{ number_format((float) $preview['chargeable_days'], 2) }} chargeable days</p>
                        <ul class="mt-2 list-inside list-disc">@foreach ($preview['segments'] as $segment)<li>{{ $segment['period_start'] }} – {{ $segment['period_end'] }}: {{ number_format((float) $segment['days'], 2) }} days</li>@endforeach</ul>
                        @foreach ($preview['warnings'] as $warning)<p class="mt-2 text-warning-700 dark:text-warning-300">{{ $warning }}</p>@endforeach
                        <p class="mt-2 text-gray-600 dark:text-gray-300">{{ $preview['attendance_change'] }} Approval may reconcile only existing Absent attendance rows; original biometric evidence is retained.</p>
                    </div>
                @elseif ($previewError)
                    <p class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-950/20 dark:text-warning-200 md:col-span-2">{{ $previewError }}</p>
                @endif

                <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10 md:col-span-2">
                    <input type="checkbox" wire:model="confirmed" class="mt-1 rounded border-gray-300">
                    <span>I confirm this entry is supported by the stated historical source. Recording is audited and does not rewrite original biometric events.</span>
                </label>
                @error('confirmed') <p class="text-danger-600 md:col-span-2">{{ $message }}</p> @enderror
                @error('reason') <p class="text-danger-600 md:col-span-2">{{ $message }}</p> @enderror
                <div class="flex justify-end md:col-span-2"><x-filament::button type="submit" :disabled="! $preview" icon="heroicon-o-check">Confirm historical record</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif

    @if ($this->canImport())
        <x-filament::section heading="Import historical leave" description="Upload a verified .xlsx register. Preview checks employee identity, verified joining dates, schedule calculations, duplicates, conflicts, and anniversary-cycle balance impact. No records are written until the entire valid batch is confirmed.">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">Imports are atomic, source-row traceable, and the same workbook cannot be imported twice.</p>
                <x-filament::button type="button" wire:click="downloadImportTemplate" icon="heroicon-o-arrow-down-tray" color="gray">Download XLSX template</x-filament::button>
            </div>
            <form wire:submit="previewImport" class="mt-4 grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                <label class="space-y-1.5 text-sm"><span class="font-medium">Historical leave workbook (.xlsx, max 10 MB)</span>
                    <input type="file" wire:model="importFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full rounded-lg border border-gray-300 p-2 dark:border-white/10 dark:bg-white/5">
                    @error('importFile') <p class="text-danger-600">{{ $message }}</p> @enderror
                    <span wire:loading wire:target="importFile" class="text-xs text-gray-500">Uploading workbook…</span>
                </label>
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="importFile,previewImport">Preview import</x-filament::button>
            </form>

            @if ($importPreview)
                <div class="mt-5 space-y-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><h3 class="font-semibold">{{ $importPreview['file_name'] }}</h3><p class="text-xs text-gray-500">SHA-256 fingerprint: {{ $importPreview['file_sha256'] }}</p></div>
                        @if ($importPreview['already_imported']) <span class="rounded-full bg-danger-50 px-3 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">Already imported</span> @endif
                    </div>
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Rows</p><p class="text-xl font-semibold">{{ $importPreview['total_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Valid</p><p class="text-xl font-semibold text-success-600">{{ $importPreview['valid_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Invalid</p><p class="text-xl font-semibold text-danger-600">{{ $importPreview['invalid_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Duplicate</p><p class="text-xl font-semibold text-warning-600">{{ $importPreview['duplicate_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Annual / Unpaid days</p><p class="text-sm font-semibold">{{ number_format((float) $importPreview['estimated_annual_days'], 2) }} / {{ number_format((float) $importPreview['estimated_unpaid_days'], 2) }}</p></div>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Annual Leave rows are checked against each employee’s verified joining-anniversary cycle and current pending/used entitlement. Approved entries require the separate Leave Approve permission. Schedule and attendance conflicts are rechecked during import.</p>
                    <div class="max-h-80 overflow-auto rounded-lg border dark:border-white/10">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900"><tr><th class="p-2">Row</th><th class="p-2">Employee</th><th class="p-2">Result</th><th class="p-2">Days</th><th class="p-2">Explanation</th></tr></thead>
                            <tbody>
                                @foreach ($importPreview['rows'] as $row)
                                    <tr class="border-t border-gray-100 dark:border-white/10"><td class="p-2">{{ $row['source_row_number'] }}</td><td class="p-2">{{ $row['employee_code'] ?: '—' }}@if($row['employee_name'])<span class="block text-xs text-gray-500">{{ $row['employee_name'] }}</span>@endif</td><td class="p-2">{{ str($row['status'])->headline() }}</td><td class="p-2">{{ $row['chargeable_days'] === null ? '—' : number_format((float) $row['chargeable_days'], 2) }}</td><td class="p-2">{{ implode(' ', $row['errors']) ?: implode(' ', $row['warnings']) ?: 'Ready' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($importPreview['invalid_rows'] > 0 || $importPreview['duplicate_rows'] > 0)
                        <x-filament::button type="button" wire:click="downloadRejectedImportRows" color="gray" icon="heroicon-o-document-arrow-down">Download rejected-row report</x-filament::button>
                    @endif
                    <form wire:submit="confirmImportBatch" class="space-y-3 rounded-lg border border-warning-300 bg-warning-50/50 p-4 dark:border-warning-500/30 dark:bg-warning-950/10">
                        <label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="confirmImport" class="mt-1 rounded border-gray-300"><span>I confirm every source row is accurate, authorized, and supported by the stated record. The import is all-or-nothing and will create permanent audit history.</span></label>
                        @error('confirmImport') <p class="text-danger-600">{{ $message }}</p> @enderror
                        <div class="flex justify-end"><x-filament::button type="submit" icon="heroicon-o-check" :disabled="$importPreview['already_imported'] || $importPreview['valid_rows'] !== $importPreview['total_rows'] || $importPreview['total_rows'] === 0">Confirm complete import</x-filament::button></div>
                    </form>
                </div>
            @endif
            @if ($this->recentImportBatches->isNotEmpty())
                <div class="mt-6 overflow-x-auto"><h3 class="mb-2 text-sm font-semibold">Recent import batches</h3><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Batch</th><th class="p-2">Workbook</th><th class="p-2">Status</th><th class="p-2">Imported</th><th class="p-2">Imported at</th></tr></thead><tbody>@foreach($this->recentImportBatches as $batch)<tr class="border-b border-gray-100 dark:border-white/10"><td class="p-2 font-mono text-xs">{{ $batch->batch_uuid }}</td><td class="p-2">{{ $batch->original_filename }}</td><td class="p-2">{{ str($batch->status)->headline() }}</td><td class="p-2">{{ $batch->imported_rows }} rows · {{ $batch->records_count }} cycle records</td><td class="p-2">{{ $batch->confirmed_at?->format('d M Y H:i') ?: '—' }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->canAdjust())
        <x-filament::section heading="Opening balance adjustment" description="Use only for a documented net opening balance where historical leave rows have not already been imported for that anniversary cycle. Each adjustment is immutable and audited.">
            <form wire:submit="recordCycleAdjustment" class="grid gap-4 md:grid-cols-2">
                <div class="space-y-2 md:col-span-2"><label class="text-sm font-medium">Employee</label><input type="search" wire:model.live.debounce.300ms="adjustmentEmployeeSearch" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Search within your authorized employee scope">
                    @error('adjustmentEmployeeId') <p class="text-danger-600">{{ $message }}</p> @enderror
                    @if ($adjustmentEmployeeId === null && $adjustmentEmployeeSearch !== '')<div class="max-h-40 overflow-y-auto rounded-lg border dark:border-white/10">@forelse($this->adjustmentEmployees() as $employee)<button type="button" wire:click="selectAdjustmentEmployee({{ $employee->id }})" class="block w-full border-b px-3 py-2 text-left text-sm last:border-0 dark:border-white/10">{{ $employee->employee_id }} · {{ $employee->name }}</button>@empty<p class="p-3 text-sm text-gray-500">No eligible employees found in your scope.</p>@endforelse</div>@endif
                </div>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Date within leave cycle</span><input type="date" wire:model.live="adjustmentCycleStart" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('adjustmentCycleStart') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                @php($adjustmentCycle = $this->adjustmentCycle())
                @if ($adjustmentCycle)
                    @if (isset($adjustmentCycle['error']))
                        <div class="rounded-lg border border-warning-300 p-3 text-sm text-warning-700 dark:border-warning-500/30 dark:text-warning-300">{{ $adjustmentCycle['error'] }}</div>
                    @else
                        <div class="rounded-lg border p-3 text-sm dark:border-white/10"><p class="font-medium">Anniversary cycle: {{ $adjustmentCycle['start'] }} – {{ $adjustmentCycle['end'] }}</p><p class="mt-1 text-gray-600 dark:text-gray-300">Entitlement {{ number_format((float) $adjustmentCycle['entitlement'], 2) }} · adjustments {{ number_format((float) $adjustmentCycle['adjustments'], 2) }} · remaining {{ number_format((float) $adjustmentCycle['remaining'], 2) }} · used {{ number_format((float) $adjustmentCycle['used'], 2) }} · pending {{ number_format((float) $adjustmentCycle['pending'], 2) }}</p></div>
                    @endif
                @endif
                <label class="space-y-1.5 text-sm"><span class="font-medium">Adjustment</span><select wire:model="adjustmentType" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="credit">Credit days</option><option value="debit">Debit days</option></select>@error('adjustmentType') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Days</span><input type="number" min="0.01" max="366" step="0.01" wire:model="adjustmentDays" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('adjustmentDays') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason</span><textarea required rows="2" wire:model="adjustmentReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('adjustmentReason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Supporting reference</span><input required type="text" maxlength="255" wire:model="adjustmentSupportingReference" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Unique payroll / HR ledger document reference">@error('adjustmentSupportingReference') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="flex items-start gap-3 text-sm md:col-span-2"><input type="checkbox" wire:model="adjustmentConfirmed" class="mt-1 rounded border-gray-300"><span>I confirm this is a net opening balance adjustment, not a duplicate of imported historical leave.</span></label>
                @error('adjustmentConfirmed') <p class="text-danger-600 md:col-span-2">{{ $message }}</p> @enderror
                <div class="flex justify-end md:col-span-2"><x-filament::button type="submit" icon="heroicon-o-check">Record balance adjustment</x-filament::button></div>
            </form>
            @if ($this->recentAdjustments->isNotEmpty())
                <div class="mt-6 overflow-x-auto"><h3 class="mb-2 text-sm font-semibold">Recent balance adjustments</h3><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Reference</th><th class="p-2">Employee</th><th class="p-2">Cycle</th><th class="p-2">Adjustment</th><th class="p-2">Reason / evidence</th><th class="p-2">Recorded by</th></tr></thead><tbody>@foreach($this->recentAdjustments as $adjustment)<tr class="border-b border-gray-100 dark:border-white/10"><td class="p-2">{{ $adjustment->reference }}</td><td class="p-2">{{ $adjustment->employee->employee_id }} · {{ $adjustment->employee->name }}</td><td class="p-2">{{ $adjustment->leave_period_start->format('d M Y') }} – {{ $adjustment->leave_period_end->format('d M Y') }}</td><td class="p-2">{{ str($adjustment->adjustment_type)->headline() }} {{ number_format((float) $adjustment->days, 2) }}</td><td class="p-2">{{ $adjustment->reason }}<span class="block text-xs text-gray-500">{{ $adjustment->supporting_reference }}</span></td><td class="p-2">{{ $adjustment->recorder->name }}<span class="block text-xs text-gray-500">{{ $adjustment->recorded_at->format('d M Y H:i') }}</span></td></tr>@endforeach</tbody></table></div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->canViewHistory())
    <x-filament::section heading="Employee leave balances" description="Balances are calculated for each employee’s verified joining-anniversary cycle. Previous completed periods are shown separately; future policy versions do not rewrite historical periods.">
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Employee</th><th class="p-2">Verified joining date</th><th class="p-2">Current leave period</th><th class="p-2">Annual entitlement</th><th class="p-2">Historical used</th><th class="p-2">Current used</th><th class="p-2">Pending</th><th class="p-2">Available</th></tr></thead><tbody>
            @forelse($this->employeeBalances as $row)
                @php($employee = $row['employee'])
                @if($row['current'])
                    <tr class="border-b border-gray-100 align-top dark:border-white/10"><td class="p-2">{{ $employee->employee_id }} · {{ $employee->name }}</td><td class="p-2">{{ $employee->joining_date->format('d M Y') }}</td><td class="p-2">{{ $row['current_cycle']->start->format('d M Y') }} – {{ $row['current_cycle']->end->format('d M Y') }}@if($row['previous'])<details class="mt-1"><summary class="cursor-pointer text-xs text-primary-600">Previous periods</summary><div class="mt-2 space-y-1">@foreach($row['previous'] as $period)<p class="text-xs">{{ $period['start'] }} – {{ $period['end'] }} · entitlement {{ number_format((float) $period['entitlement'], 2) }} · historical {{ number_format((float) $period['historical_used'], 2) }} · current {{ number_format((float) $period['current_used'], 2) }} · pending {{ number_format((float) $period['pending'], 2) }} · remaining {{ number_format((float) $period['remaining'], 2) }}</p>@endforeach</div></details>@endif</td><td class="p-2">{{ number_format((float) $row['current']->entitlement, 2) }}</td><td class="p-2">{{ number_format((float) $row['current']->historicalUsed, 2) }}</td><td class="p-2">{{ number_format((float) $row['current']->currentUsed, 2) }}</td><td class="p-2">{{ number_format((float) $row['current']->pending, 2) }}</td><td class="p-2 font-semibold">{{ number_format((float) $row['current']->availableAfterPending, 2) }}</td></tr>
                @else
                    <tr class="border-b border-gray-100 dark:border-white/10"><td class="p-2">{{ $employee->employee_id }} · {{ $employee->name }}</td><td class="p-2">{{ $employee->joining_date?->format('d M Y') }}</td><td colspan="6" class="p-2 text-warning-700">{{ $row['error'] }}</td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="p-6 text-center text-gray-500">No verified employees are visible in your leave scope.</td></tr>
            @endforelse
        </tbody></table></div><div class="mt-4">{{ $this->employeeBalances->links() }}</div>
    </x-filament::section>
    <x-filament::section heading="Historical leave history" description="Records are separate from ordinary leave requests and retain their source and audit trail.">
        <div class="mb-4 grid gap-3 md:grid-cols-[1fr_220px_auto]">
            <input type="search" wire:model.live.debounce.300ms="historyEmployeeFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Search authorized employees by code or name">
            <select wire:model.live="historyStatusFilter" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">All approval statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option></select>
            <x-filament::button type="button" wire:click="downloadHistoryExport" icon="heroicon-o-arrow-down-tray" color="gray">Export current filters</x-filament::button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Reference</th><th class="p-2">Employee</th><th class="p-2">Leave</th><th class="p-2">Days</th><th class="p-2">Status</th><th class="p-2">Source</th><th class="p-2">Recorded by</th><th class="p-2">Import batch</th><th class="p-2">Recorded at</th></tr></thead>
                <tbody>
                    @forelse ($this->records as $record)
                        <tr class="border-b border-gray-100 dark:border-white/10">
                            <td class="p-2 font-medium">{{ $record->reference }}</td>
                            <td class="p-2">{{ $record->employee->employee_id }} · {{ $record->employee->name }}</td>
                            <td class="p-2">{{ $record->type->name }}<span class="block text-xs text-gray-500">{{ $record->from_date->format('d M Y') }} – {{ $record->to_date->format('d M Y') }}</span></td>
                            <td class="p-2">{{ number_format((float) $record->chargeable_days, 2) }}</td>
                            <td class="p-2">{{ str($record->approval_status)->headline() }}</td>
                            <td class="p-2">{{ $record->historical_source ?: str($record->source)->headline() }}@if($record->original_reference)<span class="block text-xs text-gray-500">{{ $record->original_reference }}</span>@endif</td>
                            <td class="p-2">{{ $record->recorder->name }}@if($record->import_batch_id)<span class="block text-xs text-gray-500">Import row {{ $record->source_row_number }}</span>@endif</td>
                            <td class="p-2 text-xs">{{ $record->batch?->batch_uuid ?: '—' }}</td>
                            <td class="p-2">{{ $record->recorded_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="p-6 text-center text-gray-500">No historical leave records are visible in your authorized scope.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->records->links() }}</div>
    </x-filament::section>
    @endif
</x-filament-panels::page>
