<x-filament-panels::page>
    <div
        class="ac-page space-y-5"
        data-testid="module-access-control"
        x-data="{ employeePanelOpen: window.innerWidth >= 1400, wasDesktop: window.innerWidth >= 1400 }"
        x-init="$nextTick(() => employeePanelOpen = window.innerWidth >= 1400)"
        x-on:resize.window.debounce.150ms="
            const desktop = window.innerWidth >= 1400;
            if (desktop) employeePanelOpen = true;
            if (! desktop && wasDesktop) employeePanelOpen = false;
            wasDesktop = desktop;
        "
    >
        <x-filament::section compact>
            <div class="ac-filter-grid grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-12">
                <label class="min-w-0 xl:col-span-4">
                    <span class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Search Employee</span>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input wire:model.live.debounce.300ms="search" type="search" placeholder="Name, Employee ID, or email" />
                    </x-filament::input.wrapper>
                </label>
                <label class="min-w-0 xl:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Role</span>
                    <x-filament::input.wrapper><x-filament::input.select wire:model.live="role"><option value="">All roles</option>@foreach ($roleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label class="min-w-0 xl:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Team</span>
                    <x-filament::input.wrapper><x-filament::input.select wire:model.live="team"><option value="">All teams</option>@foreach ($teams as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                </label>
                <label class="min-w-0 xl:col-span-2">
                    <span class="mb-1.5 block text-sm font-medium text-gray-950 dark:text-white">Status</span>
                    <x-filament::input.wrapper><x-filament::input.select wire:model.live="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="all">All statuses</option></x-filament::input.select></x-filament::input.wrapper>
                </label>
                <div class="flex xl:col-span-2 xl:justify-end"><x-filament::button color="gray" icon="heroicon-m-x-mark" size="sm" wire:click="resetFilters">Clear Filters</x-filament::button></div>
            </div>
        </x-filament::section>

        <div class="ac-employee-trigger">
            <x-filament::button color="gray" icon="heroicon-m-users" size="sm" x-on:click="employeePanelOpen = true">
                Select Employee
            </x-filament::button>
            @if ($selectedEmployee)
                <span>{{ $selectedEmployee->name }} · {{ $selectedEmployee->employee_id }}</span>
            @endif
        </div>

        <div class="ac-employee-drawer-backdrop" x-cloak x-show="employeePanelOpen" x-transition.opacity x-on:click="employeePanelOpen = false" aria-hidden="true"></div>

        <div class="ac-workspace grid min-w-0 gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <aside class="ac-employee-panel min-w-0" x-cloak x-show="employeePanelOpen" x-transition:enter="ac-drawer-enter" x-transition:enter-start="ac-drawer-enter-start" x-transition:enter-end="ac-drawer-enter-end" x-transition:leave="ac-drawer-leave" x-transition:leave-start="ac-drawer-leave-start" x-transition:leave-end="ac-drawer-leave-end" aria-label="Employee selector">
                <x-filament::section heading="Employees" description="Select an employee to review access." compact>
                    <div class="ac-employee-drawer-close">
                        <x-filament::icon-button color="gray" icon="heroicon-m-x-mark" label="Close employee selector" x-on:click="employeePanelOpen = false" />
                    </div>
                    <div class="ac-employee-list space-y-2" data-testid="employee-picker">
                        @forelse ($employees as $employee)
                            <button type="button" wire:click="selectEmployee({{ $employee->id }})" x-on:click="if (window.innerWidth < 1400) employeePanelOpen = false" wire:key="access-employee-{{ $employee->id }}" @class([
                                'ac-employee-row group w-full min-w-0 rounded-xl border p-3 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                                'is-selected' => $selectedEmployeeId === $employee->id,
                                'border-primary-500 bg-primary-50 shadow-sm ring-1 ring-primary-500/20 dark:bg-primary-500/10' => $selectedEmployeeId === $employee->id,
                                'border-gray-200 bg-white hover:border-primary-300 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:hover:border-primary-500/50 dark:hover:bg-white/5' => $selectedEmployeeId !== $employee->id,
                            ])>
                                <span class="flex min-w-0 items-start justify-between gap-2">
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-gray-950 dark:text-white" title="{{ $employee->name }}">{{ $employee->name }}</span>
                                        <span class="mt-0.5 block text-xs font-medium text-gray-500">{{ $employee->employee_id }}</span>
                                    </span>
                                    @if ($selectedEmployeeId === $employee->id)<x-heroicon-m-check-circle class="h-5 w-5 shrink-0 text-primary-600" />@endif
                                </span>
                                <span class="mt-2 flex flex-wrap items-center gap-1.5">
                                    <x-filament::badge size="sm" color="gray">{{ $employee->role->getLabel() }}</x-filament::badge>
                                    <x-filament::badge size="sm" :color="$employee->status ? 'success' : 'danger'">{{ $employee->status ? 'Active' : 'Inactive' }}</x-filament::badge>
                                </span>
                                <span class="mt-2 flex min-w-0 items-center gap-1.5 text-xs text-gray-500">
                                    <x-heroicon-m-user-group class="h-4 w-4 shrink-0" />
                                    <span class="truncate" title="{{ $employee->team?->name ?? 'No Team' }}">{{ $employee->team?->name ?? 'No Team' }}</span>
                                </span>
                            </button>
                        @empty
                            <p class="py-6 text-center text-sm text-gray-500">No Employees match these filters.</p>
                        @endforelse
                    </div>
                    <div class="mt-4">{{ $employees->links() }}</div>
                </x-filament::section>
            </aside>

            <main class="min-w-0 space-y-4">
                @if ($selectedEmployee)
                    <div class="ac-selected-summary sticky top-20 z-10">
                    <x-filament::section compact>
                        <div class="ac-selected-card-body flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                            <div class="min-w-0">
                                <div class="ac-identity-badges flex flex-wrap items-center gap-2">
                                    <h2 class="truncate text-lg font-semibold">{{ $selectedEmployee->name }}</h2>
                                    <x-filament::badge color="gray">{{ $selectedEmployee->employee_id }}</x-filament::badge>
                                    <x-filament::badge color="gray">{{ $selectedEmployee->role->getLabel() }}</x-filament::badge>
                                    <x-filament::badge :color="$selectedEmployee->status ? 'success' : 'danger'">{{ $selectedEmployee->status ? 'Active' : 'Inactive' }}</x-filament::badge>
                                    @if ($selectedEmployee->role === \App\Enums\EmployeeRole::Owner)<x-filament::badge color="warning" icon="heroicon-m-lock-closed">Owner Protected</x-filament::badge>@endif
                                </div>
                                <div class="ac-summary-meta mt-1.5 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500">
                                    <span class="inline-flex min-w-0 items-center gap-1.5"><x-heroicon-m-user-group class="h-4 w-4 shrink-0" /><span class="truncate">{{ $selectedEmployee->team?->name ?? 'No Team' }}</span></span>
                                    <span>Default role access is used unless you set a custom permission.</span>
                                </div>
                            </div>
                            <div class="ac-summary-actions flex shrink-0 flex-wrap gap-2">
                                @if ($dirtyCount > 0)
                                    <x-filament::badge color="warning" data-testid="dirty-count">{{ $dirtyCount }} Not Saved Yet</x-filament::badge>
                                    <x-filament::button color="gray" size="sm" wire:click="discardChanges">Discard</x-filament::button>
                                    <x-filament::button size="sm" wire:click="saveChanges" wire:loading.attr="disabled" wire:target="saveChanges"><span wire:loading.remove wire:target="saveChanges">Save Changes</span><span wire:loading wire:target="saveChanges">Saving...</span></x-filament::button>
                                @else
                                    <x-filament::badge color="success" icon="heroicon-m-check">Saved</x-filament::badge>
                                @endif
                            </div>
                        </div>
                        @if ($dirtyCount > 0)
                            <div class="mt-4 max-w-2xl">
                                <label for="access-change-reason" class="mb-1 block text-sm font-medium">Change Reason{{ $hasFinancialChanges ? ' *' : '' }}</label>
                                <textarea id="access-change-reason" wire:model="changeReason" rows="2" maxlength="2000" class="w-full max-w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-gray-900" placeholder="{{ $hasFinancialChanges ? 'Required for financial access changes' : 'Optional audit note' }}"></textarea>
                                @error('changeReason')<p class="mt-1 text-sm text-danger-600">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </x-filament::section>
                    </div>

                    <x-filament::section compact>
                        <div class="ac-permission-toolbar grid items-end gap-3 md:grid-cols-2 xl:grid-cols-12">
                            <label class="min-w-0 xl:col-span-5"><span class="mb-1.5 block text-sm font-medium">Search Modules or Permissions</span><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input wire:model.live.debounce.250ms="moduleSearch" type="search" placeholder="Products, invoice, export..." /></x-filament::input.wrapper></label>
                            <label class="min-w-0 xl:col-span-3"><span class="mb-1.5 block text-sm font-medium">Permission Group</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="groupFilter"><option value="all">All groups</option>@foreach ($groups as $groupKey => $groupDefinition)<option value="{{ $groupKey }}">{{ $groupDefinition['label'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                            <label class="min-w-0 xl:col-span-3"><span class="mb-1.5 block text-sm font-medium">Override State</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="overrideFilter"><option value="all">All modules</option><option value="has_override">Has override</option><option value="allowed">Allowed override</option><option value="denied">Denied override</option></x-filament::input.select></x-filament::input.wrapper></label>
                            <div class="xl:col-span-1">
                                <x-filament::button :color="$overrideFilter === 'changed' ? 'primary' : 'gray'" size="sm" wire:click="$set('overrideFilter', '{{ $overrideFilter === 'changed' ? 'all' : 'changed' }}')" class="w-full justify-center xl:w-auto" title="Show only unsaved changes">Changed Only</x-filament::button>
                            </div>
                        </div>
                    </x-filament::section>

                    @forelse ($modulesByGroup as $groupKey => $modules)
                        @php($groupDefinition = $groups[$groupKey])
                        <details class="ac-group group rounded-xl border border-gray-200 bg-white shadow-sm open:ring-1 open:ring-gray-950/5 dark:border-white/10 dark:bg-gray-900 dark:open:ring-white/10" @if ($loop->first) open @endif data-testid="access-group-{{ $groupKey }}">
                            <summary class="ac-group-header cursor-pointer list-none px-4 py-3.5 marker:hidden sm:px-5">
                                        <div class="ac-group-title flex min-w-0 items-start gap-3">
                                    <div class="flex min-w-0 items-start gap-3">
                                        <span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/5"><x-heroicon-m-chevron-right class="h-4 w-4 transition group-open:rotate-90" /></span>
                                        <div class="min-w-0"><h3 class="font-semibold text-gray-950 dark:text-white">{{ $groupDefinition['label'] }}</h3><p class="mt-0.5 text-xs leading-5 text-gray-500">{{ $groupDefinition['description'] }}</p></div>
                                    </div>
                                </div>
                            </summary>
                            @if ($selectedEmployee->role !== \App\Enums\EmployeeRole::Owner && ! in_array($groupKey, ['dashboard', 'reports'], true) && collect($modules)->contains('can_manage_primary', true))
                                <div class="ac-group-tools flex flex-wrap items-center justify-end gap-2 border-t border-gray-100 bg-gray-50/70 px-4 py-2 dark:border-white/10 dark:bg-white/5 sm:px-5">
                                    <span class="mr-auto text-xs font-medium text-gray-500">Set every manageable module in this group</span>
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Set Group</span>
                                    <x-filament::button.group>
                                        <x-filament::button color="gray" size="xs" wire:click="setGroupAccess('{{ $groupKey }}', 'none')" wire:confirm="Set every manageable module in this group to None? Review and Save Changes afterward.">None</x-filament::button>
                                        <x-filament::button color="gray" size="xs" wire:click="setGroupAccess('{{ $groupKey }}', 'view')" wire:confirm="Set every manageable module in this group to View? Review and Save Changes afterward.">View</x-filament::button>
                                        <x-filament::button color="gray" size="xs" wire:click="setGroupAccess('{{ $groupKey }}', 'view_edit')" wire:confirm="Set every manageable module in this group to View & Edit? Advanced and financial permissions remain independent.">View & Edit</x-filament::button>
                                    </x-filament::button.group>
                                </div>
                            @endif
                            <div class="ac-module-grid grid min-w-0 gap-3 border-t border-gray-100 bg-gray-50/50 p-3 2xl:grid-cols-2 dark:border-white/10 dark:bg-black/10 sm:p-4">
                                @foreach ($modules as $module)
                                    @php($allowOverrideCount = collect($module['permissions'])->where('setting', 'allow')->count())
                                    @php($denyOverrideCount = collect($module['permissions'])->where('setting', 'deny')->count())
                                    <article class="ac-module-card min-w-0 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900" data-testid="access-module-{{ $module['key'] }}">
                                        <div class="ac-module-heading flex min-w-0 items-start justify-between gap-3">
                                            <div class="min-w-0"><h4 class="font-semibold">{{ $module['label'] }}</h4>@if ($module['description'])<p class="mt-1 text-xs leading-5 text-gray-500">{{ $module['description'] }}</p>@endif</div>
                                            <div class="ac-module-badges flex shrink-0 flex-wrap justify-end gap-1">
                                                @if ($module['changed_count'] > 0)<x-filament::badge size="sm" color="warning">Not Saved Yet</x-filament::badge>@endif
                                                @if ($allowOverrideCount > 0)<x-filament::badge size="sm" color="success">Allowed for this employee</x-filament::badge>@endif
                                                @if ($denyOverrideCount > 0)<x-filament::badge size="sm" color="danger">Blocked for this employee</x-filament::badge>@endif
                                                @if ($module['override_count'] === 0)<x-filament::badge size="sm" color="gray">Using Role Default</x-filament::badge>@endif
                                            </div>
                                        </div>
                                        @if ($module['derived'])
                                            <div class="ac-derived mt-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">Derived access · no separate permission is invented here.</div>
                                        @else
                                            <span class="sr-only">{{ collect([...$module['view_permissions'], ...$module['edit_permissions']])->pluck('label')->implode(', ') }}</span>
                                            <div class="mt-3">
                                                <div class="ac-module-access-header mb-2 flex flex-wrap items-center justify-between gap-2"><span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Module Access</span><x-filament::badge size="sm" color="gray">Default Access: {{ match($module['role_level']) {'view_edit' => 'View & Edit', 'view' => 'View', default => 'No Access'} }}</x-filament::badge></div>
                                                @if ($module['can_manage_primary'])
                                                    <div class="ac-segmented grid grid-cols-3 gap-1 rounded-xl bg-gray-100 p-1 dark:bg-white/5" role="radiogroup" aria-label="{{ $module['label'] }} access level">
                                                        @foreach (['none' => 'None', 'view' => 'View', 'view_edit' => 'View & Edit'] as $level => $label)
                                                            @php($disabled = ($level === 'view' && $module['view_keys'] === []) || ($level === 'view_edit' && $module['edit_keys'] === []))
                                                            <button type="button" wire:click="setModuleAccess('{{ $module['key'] }}', '{{ $level }}')" @disabled($disabled) aria-pressed="{{ $module['primary_level'] === $level ? 'true' : 'false' }}" @class(['min-w-0 rounded-lg px-2 py-2 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40', 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-primary-300 dark:ring-white/10' => $module['primary_level'] === $level, 'text-gray-600 hover:bg-white/80 dark:text-gray-300 dark:hover:bg-white/5' => $module['primary_level'] !== $level])>{{ $label }}</button>
                                                        @endforeach
                                                    </div>
                                                    <div class="ac-module-access-footer mt-2 flex items-center justify-between gap-2">
                                                        <span class="text-xs text-gray-500">Effective: <strong class="font-medium text-gray-700 dark:text-gray-200">{{ match($module['primary_level']) {'view_edit' => 'View & Edit', 'view' => 'View', default => 'None'} }}</strong></span>
                                                        <x-filament::button color="gray" icon="heroicon-m-arrow-path" size="xs" wire:click="inheritModule('{{ $module['key'] }}')">Reset to Role Default</x-filament::button>
                                                    </div>
                                                @else
                                                    <div class="ac-protected rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 dark:bg-white/5"><x-heroicon-m-lock-closed class="mr-1 inline h-4 w-4" /> Access protected</div>
                                                @endif
                                            </div>
                                            @if ($module['advanced_permissions'] !== [])
                                                <details class="ac-advanced group/advanced mt-3 rounded-lg border border-gray-200 dark:border-white/10">
                                                    <summary class="flex cursor-pointer list-none items-center justify-between gap-2 px-3 py-2.5 text-sm font-medium marker:hidden"><span>Advanced Permissions <span class="text-xs font-normal text-gray-500">({{ count($module['advanced_permissions']) }})</span></span><x-heroicon-m-chevron-down class="h-4 w-4 text-gray-400 transition group-open/advanced:rotate-180" /></summary>
                                                    <div class="space-y-2 border-t border-gray-100 p-3 dark:border-white/10">
                                                        @foreach ($module['advanced_permissions'] as $permissionDefinition)
                                                            @php($permission = $module['permissions'][$permissionDefinition['key']])
                                                            <div class="ac-advanced-row grid min-w-0 gap-3 rounded-lg bg-gray-50 p-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center dark:bg-white/5" data-testid="advanced-permission-{{ sha1($permission['key']) }}">
                                                                <div class="min-w-0">
                                                                    <div class="ac-permission-badges flex flex-wrap items-center gap-1.5">
                                                                        <span class="text-sm font-medium">{{ $permission['label'] }}</span>
                                                                        @if ($permission['financial'])<x-filament::badge size="sm" color="warning">Financial</x-filament::badge>@endif
                                                                        <x-filament::badge size="sm" :color="$permission['effective'] ? 'success' : 'danger'">Access: {{ $permission['effective'] ? 'Yes' : 'No' }}</x-filament::badge>
                                                                        @if ($permission['setting'] === 'inherit')<x-filament::badge size="sm" color="gray">Using Role Default</x-filament::badge>@elseif ($permission['setting'] === 'allow')<x-filament::badge size="sm" color="success">Allowed for this employee</x-filament::badge>@else<x-filament::badge size="sm" color="danger">Blocked for this employee</x-filament::badge>@endif
                                                                        @if ($permission['changed'])<x-filament::badge size="sm" color="warning">Not Saved Yet</x-filament::badge>@endif
                                                                    </div>
                                                                    <p class="mt-1 text-xs text-gray-500">Default Access: {{ $permission['role_default'] ? 'Access' : 'No Access' }}</p>
                                                                </div>
                                                                @if ($permission['can_manage'])
                                                                    <div class="ac-segmented ac-segmented-advanced grid grid-cols-3 gap-1 rounded-lg bg-gray-200/70 p-1 dark:bg-black/20" role="radiogroup" aria-label="{{ $permission['label'] }} override">
                                                                        @foreach (['inherit' => 'Reset to Role Default', 'allow' => 'Allow for Employee', 'deny' => 'Block for Employee'] as $setting => $settingLabel)
                                                                            <button type="button" wire:click="stagePermission(@js($permission['key']), '{{ $setting }}')" aria-pressed="{{ $permission['setting'] === $setting ? 'true' : 'false' }}" @class(['whitespace-nowrap rounded-md px-2 py-1.5 text-xs font-semibold transition', 'bg-white text-primary-700 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:text-primary-300 dark:ring-white/10' => $permission['setting'] === $setting, 'text-gray-600 hover:bg-white/70 dark:text-gray-300 dark:hover:bg-white/5' => $permission['setting'] !== $setting])>{{ $settingLabel }}</button>
                                                                        @endforeach
                                                                    </div>
                                                                @else<x-filament::badge color="gray" icon="heroicon-m-lock-closed">Protected</x-filament::badge>@endif
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </details>
                                            @endif
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <x-filament::section><p class="py-8 text-center text-sm text-gray-500">No modules match the current filters.</p></x-filament::section>
                    @endforelse

                    @if ($dirtyCount > 0)
                        <div class="ac-savebar sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-warning-300 bg-white/95 p-3 shadow-xl backdrop-blur dark:border-warning-500/40 dark:bg-gray-900/95" data-testid="save-access-bar"><p class="text-sm"><strong>{{ $dirtyCount }}</strong> permission {{ str('change')->plural($dirtyCount) }} not saved yet</p><div class="flex gap-2"><x-filament::button color="gray" size="sm" wire:click="discardChanges">Discard</x-filament::button><x-filament::button size="sm" wire:click="saveChanges" wire:loading.attr="disabled" wire:target="saveChanges">Save Changes</x-filament::button></div></div>
                    @endif
                @else
                    <x-filament::section><p class="py-10 text-center text-sm text-gray-500">Select an Employee to manage access.</p></x-filament::section>
                @endif
            </main>
        </div>
    </div>

    <x-filament::modal id="confirm-sensitive-permission" width="md">
        <x-slot name="heading">Confirm access change</x-slot><x-slot name="description">Review this permission change before it is applied.</x-slot>
        <dl class="grid grid-cols-3 gap-3 text-sm"><dt class="text-gray-500">Employee</dt><dd class="col-span-2 font-medium">{{ $this->pendingEmployeeName() }}</dd><dt class="text-gray-500">Permission</dt><dd class="col-span-2 font-medium">{{ $this->pendingPermissionLabel() }}</dd><dt class="text-gray-500">New setting</dt><dd class="col-span-2 font-medium">{{ $this->pendingSettingLabel() }}</dd></dl>
        <div class="mt-4 space-y-1.5"><label for="permission-reason" class="block text-sm font-medium">Reason{{ $this->pendingPermissionIsFinancial() ? ' *' : '' }}</label><textarea id="permission-reason" wire:model="pendingReason" rows="3" maxlength="2000" aria-required="{{ $this->pendingPermissionIsFinancial() ? 'true' : 'false' }}" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-gray-900"></textarea><p class="text-xs text-gray-500">{{ $this->pendingPermissionIsFinancial() ? 'Reason is required for financial access changes.' : 'Optional audit note.' }}</p></div>
        <x-slot name="footerActions"><x-filament::button wire:click="confirmSensitivePermissionChange">Confirm change</x-filament::button><x-filament::button color="gray" wire:click="cancelSensitivePermissionChange">Cancel</x-filament::button></x-slot>
    </x-filament::modal>
</x-filament-panels::page>
