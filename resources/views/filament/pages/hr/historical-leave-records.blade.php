<x-filament-panels::page class="hr-page-form hr-leave-ui">
    <x-filament::section heading="Annual Leave Setup" description="HR needs to check each employee's joining date and previous leave records before annual leave can be enabled." icon="heroicon-o-clipboard-document-check">
        <div class="hr-leave-summary">
            @foreach ([['Total Employees', $this->activationStatus['total']], ['Joining Dates Checked', $this->joiningDatesChecked], ['Balances Reviewed', $this->activationStatus['reviewed']], ['Remaining Employees', $this->activationStatus['pending']]] as [$label, $value])
                <div class="hr-leave-summary-card"><p>{{ $label }}</p><strong>{{ $value }}</strong></div>
            @endforeach
        </div>
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
            <p class="font-medium">Employees Checked: {{ $this->activationStatus['reviewed'] }} / {{ $this->activationStatus['total'] }}</p>
            <x-filament::badge :color="$this->activationStatus['active'] ? 'success' : 'warning'">{{ $this->activationStatus['active'] ? 'Annual Leave Enabled' : 'Waiting for HR Review' }}</x-filament::badge>
        </div>
        <div class="hr-leave-steps mt-5">
            <div><span class="hr-leave-step-number">1</span><h3>Check Joining Dates</h3><p>Check each employee's actual joining date. Previous values stay in the audit history.</p>@if (\App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::canAccess())<a href="#joining-date-checks" x-on:click="$dispatch('expand-section', { id: 'joining-date-checks' })" class="hr-leave-link">Choose an employee to check <span aria-hidden="true">→</span></a>@endif</div>
            <div><span class="hr-leave-step-number">2</span><h3>Add Previous Leave</h3><p>Add leave already taken, or use the Excel template for several employees.</p>@if ($this->canRecord())<a href="#previous-leave-entry" class="hr-leave-link">Add Previous Leave <span aria-hidden="true">→</span></a>@elseif ($this->canImport())<a href="#previous-leave-import" class="hr-leave-link">Import Previous Leave <span aria-hidden="true">→</span></a>@endif</div>
            <div><span class="hr-leave-step-number">3</span><h3>Check Leave Balances</h3><p>Review each balance, including employees who have not taken any previous leave.</p>@if ($this->canViewHistory())<a href="#employee-balances" class="hr-leave-link">Review employee balances <span aria-hidden="true">→</span></a>@endif</div>
            <div><span class="hr-leave-step-number">4</span><h3>Enable Annual Leave</h3><p>Owner/Admin can enable annual leave after every employee's review is complete.</p>
                @if ($this->canActivate() && ! $this->activationStatus['active'])
                    <x-filament::button class="mt-3" size="sm" type="button" wire:click="activateAnnualLeaveWorkflow" wire:confirm="Confirm that every employee's joining date and historical leave balance has been reviewed? This activates employee Annual Leave requests." :disabled="$this->activationStatus['pending'] > 0">Enable Annual Leave</x-filament::button>
                @elseif ($this->activationStatus['active'])
                    <p class="mt-3 font-medium text-success-600 dark:text-success-400">Annual leave is enabled.</p>
                @endif
            </div>
        </div>
        @error('activation') <p role="alert" class="mt-3 text-sm text-danger-600">{{ $message }}</p> @enderror
    </x-filament::section>
    @if (\App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::canAccess())
        <x-filament::section id="joining-date-checks" heading="Check Joining Dates" description="Choose an employee to open the existing joining-date verification page. Dates are saved only after the required reason and confirmation." icon="heroicon-o-calendar-days" collapsible :collapsed="$this->joiningDatesChecked > 0 && $joiningDateSearch === ''">
            <label class="mb-4 block max-w-md space-y-1 text-sm"><span class="font-medium">Find Employee</span><input type="search" wire:model.live.debounce.300ms="joiningDateSearch" placeholder="Search by employee name or code"></label>
            <div class="divide-y divide-gray-100 dark:divide-white/10">
                @forelse ($this->joiningDateEmployees as $employee)
                    <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="joining-check-{{ $employee->id }}"><div><p class="text-sm font-medium">{{ $employee->name }} <span class="font-normal text-gray-500">· {{ $employee->employee_id }}</span></p><p class="mt-1 text-xs text-gray-500">Joining Date: {{ $employee->joining_date?->format('d M Y') ?: 'Not entered' }} · {{ $employee->joining_date_verified_at ? 'Checked by HR' : 'Needs checking' }}</p></div><x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id])">Check Joining Date</x-filament::button></div>
                @empty
                    <p class="py-4 text-sm text-gray-500">No employees match your search. Try another name or employee code.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $this->joiningDateEmployees->links() }}</div>
        </x-filament::section>
    @endif
    @if ($this->canRecord())
        <x-filament::section id="previous-leave-entry" :heading="$correctingRecordId ? 'Correct Previous Leave' : 'Add Previous Leave'" description="Choose an employee and leave dates. Check the calculated days before saving." icon="heroicon-o-document-plus">
            @if ($correctingRecordId)
                <div class="mb-4 rounded-lg border border-warning-300 p-3 text-sm dark:border-warning-500/30">Correcting one historical record. The original remains in the audit history and is voided only if the replacement passes validation. <button type="button" wire:click="cancelCorrection" class="font-medium text-primary-600 underline">Cancel correction</button></div>
            @endif
            <form wire:submit="recordHistoricalLeave" class="grid gap-4 md:grid-cols-2">
                <div class="space-y-2 md:col-span-2">
                    <label for="historical-employee" class="text-sm font-medium">Employee <span class="text-danger-600">*</span></label>
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

                <label class="space-y-1.5 text-sm"><span class="font-medium">Leave Type <span class="text-danger-600">*</span></span>
                    <select wire:model.live="leaveTypeId" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="">Select leave type</option>@foreach ($this->leaveTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select>
                    @error('leaveTypeId') <p class="text-danger-600">{{ $message }}</p> @enderror
                </label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Status <span class="text-danger-600">*</span></span>
                    <select wire:model.live="approvalStatus" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="pending">Pending Approval</option>@if ($this->canApprove())<option value="approved">Approved</option>@endif</select>
                    @error('approvalStatus') <p class="text-danger-600">{{ $message }}</p> @enderror
                </label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Start Date <span class="text-danger-600">*</span></span><input type="date" required wire:model.live="fromDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('fromDate') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">End Date <span class="text-danger-600">*</span></span><input type="date" required wire:model.live="toDate" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('toDate') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason <span class="text-danger-600">*</span></span><textarea rows="3" required maxlength="2000" wire:model="reason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Example: Leave taken during a family emergency, confirmed from HR records"></textarea>@error('reason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Record Source</span><input type="text" maxlength="120" wire:model="historicalSource" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Example: Signed leave register">@error('historicalSource') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <details class="hr-leave-details md:col-span-2" @if ($chargeableDays !== '' || $originalReference !== '' || $errors->hasAny(['chargeableDays', 'manualOverrideReason', 'originalReference', 'originalApprover', 'originalApprovedAt', 'remarks'])) open @endif>
                    <summary>Additional Details <span>Previous references, notes and verified day counts</span></summary>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="space-y-1.5 text-sm"><span class="font-medium">Previous Record Number</span><input type="text" maxlength="255" wire:model="originalReference" placeholder="Reference from the previous register">@error('originalReference') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                        <label class="space-y-1.5 text-sm"><span class="font-medium">Approved By</span><input type="text" maxlength="255" wire:model="originalApprover" placeholder="Name shown on the original record">@error('originalApprover') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                        <label class="space-y-1.5 text-sm"><span class="font-medium">Approval Date</span><input type="date" wire:model="originalApprovedAt">@error('originalApprovedAt') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                        <label class="space-y-1.5 text-sm"><span class="font-medium">Leave Days</span><input type="number" min="0.5" max="366" step="0.5" wire:model.live="chargeableDays" placeholder="Calculated automatically when workdays are set"><span class="block text-xs text-gray-500">Only enter a verified override when needed. A reason and the existing validation checks are required.</span>@error('chargeableDays') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                        @if ($chargeableDays !== '')<label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason for Entering Leave Days <span class="text-danger-600">*</span></span><textarea rows="2" maxlength="2000" wire:model="manualOverrideReason"></textarea>@error('manualOverrideReason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>@endif
                        <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Notes</span><textarea rows="2" maxlength="10000" wire:model="remarks" placeholder="Any other information from the previous record"></textarea>@error('remarks') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                    </div>
                </details>
                @if ($correctingRecordId)
                    <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Correction reason</span><textarea rows="2" required maxlength="2000" wire:model="correctionReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('correctionReason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                @endif

                @if ($preview)
                    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm dark:border-primary-500/30 dark:bg-primary-950/20 md:col-span-2">
                        <p class="font-semibold">Leave Preview — {{ number_format((float) $preview['chargeable_days'], 2) }} Leave Days</p>
                        <p class="mt-1">{{ $preview['employee_code'] }} · {{ $preview['employee_name'] }} · {{ $preview['leave_type'] }}</p>
                        <ul class="mt-2 list-inside list-disc">@foreach ($preview['segments'] as $segment)<li>{{ $segment['period_start'] }} – {{ $segment['period_end'] }}: {{ number_format((float) $segment['days'], 2) }} days</li>@endforeach</ul>
                        @foreach ($preview['warnings'] as $warning)<p class="mt-2 text-warning-700 dark:text-warning-300">{{ $warning }}</p>@endforeach
                        <p class="mt-2 text-gray-600 dark:text-gray-300">Approved historical leave affects leave balances only. Existing attendance and biometric records remain unchanged.</p>
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
                <div class="flex justify-end md:col-span-2"><x-filament::button type="submit" :disabled="! $preview" icon="heroicon-o-check" wire:loading.attr="disabled" wire:target="recordHistoricalLeave">{{ $correctingRecordId ? 'Save Correction' : 'Save Previous Leave' }}</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif

    @if ($this->canImport())
        <x-filament::section id="previous-leave-import" heading="Import Previous Leave" description="Download the template, upload your Excel file, then check every row before importing." icon="heroicon-o-table-cells" collapsible :collapsed="! $importPreview && ! $errors->has('importFile')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-600 dark:text-gray-300">All rows must pass the checks. The same file cannot be imported twice.</p>
                <x-filament::button type="button" wire:click="downloadImportTemplate" icon="heroicon-o-arrow-down-tray" color="gray">Download Template</x-filament::button>
            </div>
            <form wire:submit="previewImport" class="mt-4 grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                <label class="space-y-1.5 text-sm"><span class="font-medium">Upload Excel File <span class="text-danger-600">*</span> <span class="font-normal text-gray-500">(.xlsx, up to 10 MB)</span></span>
                    <input type="file" wire:model="importFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="w-full rounded-lg border border-gray-300 p-2 dark:border-white/10 dark:bg-white/5">
                    @error('importFile') <p class="text-danger-600">{{ $message }}</p> @enderror
                    <span wire:loading wire:target="importFile" class="text-xs text-gray-500">Uploading workbook…</span>
                </label>
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled" wire:target="importFile,previewImport">Check Excel File</x-filament::button>
            </form>

            @if ($importPreview)
                <div class="mt-5 space-y-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><h3 class="font-semibold">{{ $importPreview['file_name'] }}</h3><p class="text-xs text-gray-500">Preview only — nothing has been imported yet.</p></div>
                        @if ($importPreview['already_imported']) <span class="rounded-full bg-danger-50 px-3 py-1 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">Already imported</span> @endif
                    </div>
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Rows</p><p class="text-xl font-semibold">{{ $importPreview['total_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Valid</p><p class="text-xl font-semibold text-success-600">{{ $importPreview['valid_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Invalid</p><p class="text-xl font-semibold text-danger-600">{{ $importPreview['invalid_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Duplicate</p><p class="text-xl font-semibold text-warning-600">{{ $importPreview['duplicate_rows'] }}</p></div>
                        <div class="rounded-lg border p-3 dark:border-white/10"><p class="text-xs text-gray-500">Annual / Unpaid days</p><p class="text-sm font-semibold">{{ number_format((float) $importPreview['estimated_annual_days'], 2) }} / {{ number_format((float) $importPreview['estimated_unpaid_days'], 2) }}</p></div>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Annual Leave rows are checked against the employee’s Leave Year, based on the verified joining date, and leave already used or waiting for approval. Approved entries require the separate Leave Approve permission. Work schedule and attendance conflicts are checked again during import.</p>
                    <div class="max-h-80 overflow-auto rounded-lg border dark:border-white/10">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900"><tr><th class="p-2">Employee</th><th class="p-2">Leave Dates</th><th class="p-2">Days</th><th class="p-2">Status</th><th class="p-2">Errors</th></tr></thead>
                            <tbody>
                                @foreach ($importPreview['rows'] as $row)
                                    <tr class="border-t border-gray-100 dark:border-white/10"><td class="p-2">{{ $row['employee_name'] ?: $row['employee_code'] ?: 'Unknown employee' }}<span class="block text-xs text-gray-500">{{ $row['employee_code'] }} · Excel row {{ $row['source_row_number'] }}</span></td><td class="p-2">{{ data_get($row, 'data.from_date') ?: '—' }} – {{ data_get($row, 'data.to_date') ?: '—' }}</td><td class="p-2">{{ $row['chargeable_days'] === null ? '—' : number_format((float) $row['chargeable_days'], 2) }}</td><td class="p-2"><x-filament::badge :color="$row['status'] === 'valid' ? 'success' : 'warning'">{{ str($row['status'])->headline() }}</x-filament::badge></td><td class="p-2">{{ implode(' ', $row['errors']) ?: implode(' ', $row['warnings']) ?: 'Ready to import' }}</td></tr>
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
                        <div class="flex justify-end"><x-filament::button type="submit" icon="heroicon-o-check" wire:confirm="Import every checked row? All rows must pass validation and the import will be recorded in the audit history." :disabled="$importPreview['already_imported'] || $importPreview['valid_rows'] !== $importPreview['total_rows'] || $importPreview['total_rows'] === 0">Import Checked Rows</x-filament::button></div>
                    </form>
                </div>
            @endif
            @if ($this->recentImportBatches->isNotEmpty())
                <div class="mt-6 overflow-x-auto"><h3 class="mb-2 text-sm font-semibold">Recent import batches</h3><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Batch</th><th class="p-2">Workbook</th><th class="p-2">Status</th><th class="p-2">Imported</th><th class="p-2">Imported at</th></tr></thead><tbody>@foreach($this->recentImportBatches as $batch)<tr class="border-b border-gray-100 dark:border-white/10"><td class="p-2 font-mono text-xs">{{ $batch->batch_uuid }}</td><td class="p-2">{{ $batch->original_filename }}</td><td class="p-2">{{ str($batch->status)->headline() }}</td><td class="p-2">{{ $batch->imported_rows }} rows · {{ $batch->records_count }} cycle records</td><td class="p-2">{{ $batch->confirmed_at?->format('d M Y H:i') ?: '—' }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->canAdjust())
        <x-filament::section heading="Advanced Options" description="Adjust Leave Balance only when a documented balance needs correction. Do not duplicate previous leave entries." icon="heroicon-o-adjustments-horizontal" collapsible collapsed>
            <h3 class="mb-3 font-semibold">Adjust Leave Balance</h3>
            <form wire:submit="recordCycleAdjustment" class="grid gap-4 md:grid-cols-2">
                <div class="space-y-2 md:col-span-2"><label class="text-sm font-medium">Employee</label><input type="search" wire:model.live.debounce.300ms="adjustmentEmployeeSearch" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Search within your authorized employee scope">
                    @error('adjustmentEmployeeId') <p class="text-danger-600">{{ $message }}</p> @enderror
                    @if ($adjustmentEmployeeId === null && $adjustmentEmployeeSearch !== '')<div class="max-h-40 overflow-y-auto rounded-lg border dark:border-white/10">@forelse($this->adjustmentEmployees() as $employee)<button type="button" wire:click="selectAdjustmentEmployee({{ $employee->id }})" class="block w-full border-b px-3 py-2 text-left text-sm last:border-0 dark:border-white/10">{{ $employee->employee_id }} · {{ $employee->name }}</button>@empty<p class="p-3 text-sm text-gray-500">No eligible employees found in your scope.</p>@endforelse</div>@endif
                </div>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Date within Leave Year</span><input type="date" wire:model.live="adjustmentCycleStart" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('adjustmentCycleStart') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                @php($adjustmentCycle = $this->adjustmentCycle())
                @if ($adjustmentCycle)
                    @if (isset($adjustmentCycle['error']))
                        <div class="rounded-lg border border-warning-300 p-3 text-sm text-warning-700 dark:border-warning-500/30 dark:text-warning-300">{{ $adjustmentCycle['error'] }}</div>
                    @else
                        <div class="rounded-lg border p-3 text-sm dark:border-white/10"><p class="font-medium">Leave Year: {{ $adjustmentCycle['start'] }} – {{ $adjustmentCycle['end'] }}</p><p class="mt-1 text-gray-600 dark:text-gray-300">Allowed Days {{ number_format((float) $adjustmentCycle['entitlement'], 2) }} · Balance Changes {{ number_format((float) $adjustmentCycle['adjustments'], 2) }} · Days Left {{ number_format((float) $adjustmentCycle['remaining'], 2) }} · Days Used {{ number_format((float) $adjustmentCycle['used'], 2) }} · Waiting for Approval {{ number_format((float) $adjustmentCycle['pending'], 2) }}</p></div>
                    @endif
                @endif
                <label class="space-y-1.5 text-sm"><span class="font-medium">Adjustment</span><select wire:model="adjustmentType" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"><option value="credit">Add days</option><option value="debit">Subtract days</option></select>@error('adjustmentType') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm"><span class="font-medium">Days</span><input type="number" min="0.01" max="366" step="0.01" wire:model="adjustmentDays" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">@error('adjustmentDays') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Reason</span><textarea required rows="2" wire:model="adjustmentReason" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"></textarea>@error('adjustmentReason') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="space-y-1.5 text-sm md:col-span-2"><span class="font-medium">Supporting reference</span><input required type="text" maxlength="255" wire:model="adjustmentSupportingReference" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Unique payroll or HR document reference">@error('adjustmentSupportingReference') <p class="text-danger-600">{{ $message }}</p> @enderror</label>
                <label class="flex items-start gap-3 text-sm md:col-span-2"><input type="checkbox" wire:model="adjustmentConfirmed" class="mt-1 rounded border-gray-300"><span>I confirm this corrects the opening balance and does not count previous leave twice.</span></label>
                @error('adjustmentConfirmed') <p class="text-danger-600 md:col-span-2">{{ $message }}</p> @enderror
                <div class="flex justify-end md:col-span-2"><x-filament::button type="submit" icon="heroicon-o-check" wire:confirm="Save this documented balance adjustment? Check that the same leave has not already been entered or imported.">Save Balance Adjustment</x-filament::button></div>
            </form>
            @if ($this->recentAdjustments->isNotEmpty())
                <div class="mt-6 overflow-x-auto"><h3 class="mb-2 text-sm font-semibold">Recent balance adjustments</h3><table class="w-full text-left text-sm"><thead><tr class="border-b text-xs uppercase text-gray-500"><th class="p-2">Reference</th><th class="p-2">Employee</th><th class="p-2">Leave Year</th><th class="p-2">Adjustment</th><th class="p-2">Reason / evidence</th><th class="p-2">Recorded by</th></tr></thead><tbody>@foreach($this->recentAdjustments as $adjustment)<tr class="border-b border-gray-100 dark:border-white/10"><td class="p-2">{{ $adjustment->reference }}</td><td class="p-2">{{ $adjustment->employee->employee_id }} · {{ $adjustment->employee->name }}</td><td class="p-2">{{ $adjustment->leave_period_start->format('d M Y') }} – {{ $adjustment->leave_period_end->format('d M Y') }}</td><td class="p-2">{{ str($adjustment->adjustment_type)->headline() }} {{ number_format((float) $adjustment->days, 2) }}</td><td class="p-2">{{ $adjustment->reason }}<span class="block text-xs text-gray-500">{{ $adjustment->supporting_reference }}</span></td><td class="p-2">{{ $adjustment->recorder->name }}<span class="block text-xs text-gray-500">{{ $adjustment->recorded_at->format('d M Y H:i') }}</span></td></tr>@endforeach</tbody></table></div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->canViewHistory())
    <x-filament::section id="employee-balances" heading="Employee Leave Balances" description="Check joining dates, previous leave and the days left. Confirm each employee's review when the figures are correct." icon="heroicon-o-users">
        @if (! $this->activationStatus['active']) <p class="mb-3 text-sm font-semibold text-warning-700">Waiting for HR Review</p> @endif
        @if ($this->joiningDatesChecked === 0)
            <div role="status" class="hr-leave-notice mb-4">No joining dates have been checked yet. Choose <strong>Check Joining Date</strong> beside an employee to begin. Balances become available after HR verifies the date.</div>
        @endif
        <label class="mb-4 block max-w-md space-y-1 text-sm"><span class="font-medium">Find Employee</span><input type="search" wire:model.live.debounce.300ms="historyEmployeeFilter" placeholder="Search by employee name or code"></label>
        <div class="hr-leave-table-scroll"><table class="hr-leave-table"><thead><tr><th>Employee</th><th>Joining Date</th><th>Leave Year</th><th>Allowed Days</th><th>Days Used</th><th>Pending</th><th>Days Left</th><th>Status</th></tr></thead><tbody>
            @forelse($this->employeeBalances as $row)
                @php($employee = $row['employee'])
                @if($row['current'])
                    <tr><td><p class="font-medium">{{ $employee->name }}</p><span class="text-xs text-gray-500">{{ $employee->employee_id }}</span></td><td>{{ $employee->joining_date->format('d M Y') }}@if(\App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::canAccess())<a href="{{ \App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id]) }}" class="hr-leave-link block text-xs">Check Joining Date</a>@endif</td><td>{{ $row['current_cycle']->start->format('d M Y') }}<span class="block text-xs text-gray-500">to {{ $row['current_cycle']->end->format('d M Y') }}</span>@if($row['previous'])<details class="mt-2"><summary class="cursor-pointer text-xs text-primary-600">Previous years</summary><div class="mt-2 space-y-2">@foreach($row['previous'] as $period)<p class="text-xs">{{ $period['start'] }} – {{ $period['end'] }}<br>Allowed {{ number_format((float) $period['entitlement'], 2) }} · Previous leave {{ number_format((float) $period['historical_used'], 2) }} · ERP leave {{ number_format((float) $period['current_used'], 2) }} · Pending {{ number_format((float) $period['pending'], 2) }} · Left {{ number_format((float) $period['remaining'], 2) }}</p>@endforeach</div></details>@endif</td><td>{{ number_format((float) $row['current']->entitlement, 2) }}</td><td>{{ number_format((float) $row['current']->used, 2) }}<details class="mt-2"><summary class="cursor-pointer text-xs text-primary-600">Breakdown</summary><p class="mt-1 text-xs">Previous leave: {{ number_format((float) $row['current']->historicalUsed, 2) }}<br>ERP leave: {{ number_format((float) $row['current']->currentUsed, 2) }}<br>Balance adjustments: {{ number_format((float) $row['current']->adjustments, 2) }}</p></details></td><td>{{ number_format((float) $row['current']->pending, 2) }}</td><td class="font-semibold">{{ number_format((float) $row['current']->availableAfterPending, 2) }}</td><td><x-filament::badge :color="$row['reviewed'] ? 'success' : 'warning'">{{ $row['reviewed'] ? 'HR reviewed' : 'Pending HR review' }}</x-filament::badge>@if($this->canRecord() && $this->canViewHistory() && ! $row['reviewed'])<button type="button" wire:click="confirmEmployeeReconciliation({{ $employee->id }})" wire:confirm="Confirm you have checked this employee's joining date, previous leave (including no previous leave), and calculated balance?" class="hr-leave-link mt-2 text-xs">Confirm Review</button>@endif</td></tr>
                @else
                    <tr><td><p class="font-medium">{{ $employee->name }}</p><span class="text-xs text-gray-500">{{ $employee->employee_id }}</span></td><td>{{ $employee->joining_date?->format('d M Y') ?: 'Not entered' }}@if(\App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::canAccess())<a href="{{ \App\Filament\Pages\Hr\VerifyEmployeeJoiningDate::getUrl(['employee' => $employee->id]) }}" class="hr-leave-link block text-xs">Check Joining Date</a>@endif</td><td colspan="5" class="text-gray-500">{{ $row['error'] }}</td><td><x-filament::badge color="warning">Check Joining Date</x-filament::badge></td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="text-center text-gray-500">No employees match your search. Try another name or employee code.</td></tr>
            @endforelse
        </tbody></table></div><div class="mt-4">{{ $this->employeeBalances->links() }}</div>
    </x-filament::section>
    <x-filament::section heading="Previous Leave Records" description="Search previous entries. Open Details to see the source, recording history and correction actions." icon="heroicon-o-clock">
        @if ($voidingRecordId)
            <form wire:submit="confirmVoid" class="mb-4 space-y-2 rounded-lg border border-warning-300 p-3 text-sm dark:border-warning-500/30">
                <p>Void the selected historical entry. Its original details and audit history remain retained.</p>
                <textarea wire:model="voidReason" required maxlength="2000" rows="2" class="w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5" placeholder="Required reason for voiding"></textarea>
                @error('voidReason') <p class="text-danger-600">{{ $message }}</p> @enderror
                <x-filament::button type="submit" color="danger" wire:confirm="Void this previous leave entry? Its original details and audit history will be retained.">Confirm Void</x-filament::button>
            </form>
        @endif
        <div class="mb-4 grid gap-3 md:grid-cols-[1fr_220px_auto]">
            <label class="space-y-1 text-sm"><span class="font-medium">Employee</span><input type="search" wire:model.live.debounce.300ms="historyEmployeeFilter" placeholder="Search by employee name or code"></label>
            <label class="space-y-1 text-sm"><span class="font-medium">Status</span><select wire:model.live="historyStatusFilter"><option value="">All Statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option><option value="voided">Voided</option></select></label>
            <x-filament::button type="button" wire:click="downloadHistoryExport" icon="heroicon-o-arrow-down-tray" color="gray">Export current filters</x-filament::button>
        </div>
        <div class="hr-leave-table-scroll">
            <table class="hr-leave-table">
                <thead><tr><th>Employee</th><th>Leave Dates</th><th>Days</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                    @forelse ($this->records as $record)
                        <tr class="border-b border-gray-100 dark:border-white/10">
                            <td><p class="font-medium">{{ $record->employee->name }}</p><span class="text-xs text-gray-500">{{ $record->employee->employee_id }}</span></td>
                            <td>{{ $record->type->name }}<span class="block text-xs text-gray-500">{{ $record->from_date->format('d M Y') }} – {{ $record->to_date->format('d M Y') }}</span></td>
                            <td>{{ number_format((float) $record->chargeable_days, 2) }}</td>
                            <td><x-filament::badge :color="match($record->approval_status) {'approved' => 'success', 'pending' => 'warning', 'voided', 'rejected' => 'danger', default => 'gray'}">{{ str($record->approval_status)->headline() }}</x-filament::badge></td>
                            <td><details><summary class="hr-leave-link cursor-pointer">Details</summary><dl class="hr-leave-record-details mt-3"><dt>Reference</dt><dd>{{ $record->reference }}</dd><dt>Record Source</dt><dd>{{ $record->historical_source ?: str($record->source)->headline() }}</dd><dt>Previous Record Number</dt><dd>{{ $record->original_reference ?: '—' }}</dd><dt>Recorded By</dt><dd>{{ $record->recorder->name }} · {{ $record->recorded_at->format('d M Y H:i') }}</dd><dt>Approved By</dt><dd>{{ $record->original_approver ?: $record->approver?->name ?: '—' }}</dd><dt>Approval Date</dt><dd>{{ $record->original_approved_at?->format('d M Y') ?: '—' }}</dd><dt>Reason / Notes</dt><dd>{{ $record->reason }}{{ $record->remarks ? ' · '.$record->remarks : '' }}</dd>@if($record->import_batch_id)<dt>Import</dt><dd>{{ $record->batch?->batch_uuid }} · Row {{ $record->source_row_number }}</dd>@endif @if($record->correction_of_record_id)<dt>Correction Of</dt><dd>#{{ $record->correction_of_record_id }}</dd>@endif</dl>@if($this->canRecord() && in_array($record->approval_status, ['pending', 'approved'], true) && ($record->approval_status !== 'approved' || $this->canApprove()))<div class="mt-3 flex gap-3 text-xs"><button type="button" wire:click="startCorrection({{ $record->id }})" x-on:click="document.getElementById('previous-leave-entry').scrollIntoView({ behavior: 'smooth' })" class="hr-leave-link">Correct</button><button type="button" wire:click="startVoid({{ $record->id }})" class="text-danger-600 underline">Void</button></div>@endif</details></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-gray-500">No previous leave records found. Add an entry or import the Excel template when you have verified source records.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $this->records->links() }}</div>
    </x-filament::section>
    @endif
</x-filament-panels::page>
