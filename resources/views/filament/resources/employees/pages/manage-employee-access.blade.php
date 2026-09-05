<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-semibold">{{ $this->getRecord()->name }}</div>
                    <div class="text-sm text-gray-500">
                        {{ $this->getRecord()->employee_id }} · {{ $this->getRecord()->role->getLabel() }}
                    </div>
                </div>
                <div class="text-sm text-gray-500">Choose Use Role Default to follow the Employee role, or apply an explicit Allow or Block setting.</div>
            </div>
        </x-filament::section>

        @foreach ($this->getPermissionGroups() as $group => $permissions)
            <x-filament::section :heading="$group">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead>
                            <tr class="text-left">
                                <th class="px-3 py-3">Permission</th>
                                <th class="px-3 py-3">Role Default</th>
                                <th class="px-3 py-3">Access Setting</th>
                                <th class="px-3 py-3">Effective Access</th>
                                <th class="min-w-64 px-3 py-3">Reason</th>
                                <th class="px-3 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($permissions as $permission)
                                <tr wire:key="permission-{{ $permission['token'] }}">
                                    <td class="px-3 py-3">
                                        <div class="font-medium">{{ $permission['label'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $permission['key'] }}</div>
                                    </td>
                                    <td class="px-3 py-3">{{ $permission['role_default'] ? 'Yes' : 'No' }}</td>
                                    <td class="px-3 py-3">
                                        <select wire:model="changes.{{ $permission['token'] }}.effect" class="rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-gray-900">
                                            <option value="inherit">Use Role Default</option>
                                            <option value="allow">Allow</option>
                                            <option value="deny">Block</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span @class([
                                            'rounded-full px-2 py-1 text-xs font-medium',
                                            'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $permission['effective'],
                                            'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => ! $permission['effective'],
                                        ])>{{ $permission['effective'] ? 'Yes' : 'No' }}</span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <input
                                            type="text"
                                            wire:model="changes.{{ $permission['token'] }}.reason"
                                            maxlength="2000"
                                            placeholder="{{ $permission['financial'] ? 'Required for financial access' : 'Optional reason' }}"
                                            class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-gray-900"
                                        >
                                        @error("changes.{$permission['token']}.reason")
                                            <div class="mt-1 text-xs text-danger-600">{{ $message }}</div>
                                        @enderror
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        @if ($permission['sensitive'])
                                            <x-filament::button
                                                size="sm"
                                                wire:click="savePermission('{{ $permission['key'] }}')"
                                                wire:confirm="Confirm this sensitive permission change?"
                                            >Save</x-filament::button>
                                        @else
                                            <x-filament::button
                                                size="sm"
                                                wire:click="savePermission('{{ $permission['key'] }}')"
                                            >Save</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
