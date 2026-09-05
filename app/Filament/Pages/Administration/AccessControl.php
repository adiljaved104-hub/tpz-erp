<?php

namespace App\Filament\Pages\Administration;

use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Throwable;

class AccessControl extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.administration.access-control';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Access Control';

    protected static ?string $title = 'Employee Access Control';

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $team = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $moduleSearch = '';

    #[Url]
    public string $groupFilter = 'all';

    #[Url]
    public string $overrideFilter = 'all';

    /** Legacy URL property retained so existing shared links continue to open safely. */
    #[Url]
    public string $group = 'Orders';

    public ?int $selectedEmployeeId = null;

    /** @var array<string, string> */
    public array $draftSettings = [];

    /** @var array<string, string> */
    public array $originalSettings = [];

    public string $changeReason = '';

    public ?int $pendingEmployeeId = null;

    public ?string $pendingPermissionKey = null;

    public ?string $pendingSetting = null;

    public string $pendingReason = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->employee?->status === true
            && in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->selectedEmployeeId = $this->employeeQuery()->value('id');
        $this->loadDraftSettings();
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'team', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search = $this->role = $this->team = $this->moduleSearch = '';
        $this->status = 'active';
        $this->groupFilter = $this->overrideFilter = 'all';
        $this->resetPage();
    }

    public function selectEmployee(int $employeeId): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->employeeQuery()->whereKey($employeeId)->exists(), 404);
        $this->selectedEmployeeId = $employeeId;
        $this->changeReason = '';
        $this->loadDraftSettings();
    }

    public function stagePermission(string $permissionKey, string $setting): void
    {
        $employee = $this->selectedEmployee();
        $this->authorizeManagedChange($employee, $permissionKey, $setting);
        $this->draftSettings[$permissionKey] = $setting;
    }

    public function setModuleAccess(string $moduleKey, string $level): void
    {
        abort_unless(in_array($level, ['none', 'view', 'view_edit'], true), 422);
        $module = app(AccessControlModuleRegistry::class)->keyed()[$moduleKey] ?? null;
        abort_unless(is_array($module) && ! $module['derived'], 404);
        $employee = $this->selectedEmployee();

        foreach ($module['view_keys'] as $key) {
            $setting = $level === 'none' ? 'deny' : 'allow';
            $this->authorizeManagedChange($employee, $key, $setting);
            $this->draftSettings[$key] = $setting;
        }
        foreach ($module['edit_keys'] as $key) {
            $setting = $level === 'view_edit' ? 'allow' : 'deny';
            $this->authorizeManagedChange($employee, $key, $setting);
            $this->draftSettings[$key] = $setting;
        }
    }

    public function inheritModule(string $moduleKey): void
    {
        $module = app(AccessControlModuleRegistry::class)->keyed()[$moduleKey] ?? null;
        abort_unless(is_array($module) && ! $module['derived'], 404);
        $employee = $this->selectedEmployee();

        foreach ([...$module['view_keys'], ...$module['edit_keys']] as $key) {
            $this->authorizeManagedChange($employee, $key, 'inherit');
            $this->draftSettings[$key] = 'inherit';
        }
    }

    public function setGroupAccess(string $groupKey, string $level): void
    {
        abort_unless(array_key_exists($groupKey, app(AccessControlModuleRegistry::class)->groups()), 404);
        abort_unless(in_array($level, ['none', 'view', 'view_edit'], true), 422);

        foreach (app(AccessControlModuleRegistry::class)->modules() as $module) {
            if ($module['group'] === $groupKey && ! $module['derived']) {
                $this->setModuleAccess($module['key'], $level);
            }
        }
    }

    public function discardChanges(): void
    {
        $this->draftSettings = $this->originalSettings;
        $this->changeReason = '';
    }

    public function saveChanges(): void
    {
        abort_unless(static::canAccess(), 403);
        $employee = $this->selectedEmployee();
        $changes = $this->pendingChanges();
        if ($changes === []) {
            Notification::make()->info()->title('No access changes to save')->send();

            return;
        }

        $catalog = app(EmployeePermissionCatalog::class);
        $requiresReason = false;
        foreach ($changes as $key => $setting) {
            $this->authorizeManagedChange($employee, $key, $setting);
            $requiresReason = $requiresReason || (bool) ($catalog->find($key)['financial'] ?? false);
        }
        $reason = filled($this->changeReason) ? trim($this->changeReason) : null;
        if ($requiresReason && $reason === null) {
            throw ValidationException::withMessages(['changeReason' => 'A reason is required when changing financial access.']);
        }

        try {
            DB::transaction(function () use ($changes, $employee, $reason): void {
                foreach ($changes as $key => $setting) {
                    app(EmployeePermissionOverrideService::class)->change(
                        $employee,
                        $key,
                        match ($setting) {
                            'allow' => EmployeePermissionEffect::Allow,
                            'deny' => EmployeePermissionEffect::Deny,
                            default => null,
                        },
                        $reason,
                        auth()->user(),
                    );
                }
            });
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Access changes could not be saved')->body('No partial permission changes were applied.')->send();

            return;
        }

        $this->changeReason = '';
        $this->loadDraftSettings();
        Notification::make()->success()->title('Access changes saved')->body(count($changes).' permission '.str('change')->plural(count($changes)).' applied.')->send();
    }

    /** Compatibility entry point for existing employee-access integrations. */
    public function requestPermissionChange(int $employeeId, string $permissionKey, string $setting): void
    {
        abort_unless(static::canAccess(), 403);
        $employee = Employee::query()->findOrFail($employeeId);
        $definition = app(EmployeePermissionCatalog::class)->find($permissionKey);
        if ($definition === null || ! in_array($setting, ['inherit', 'allow', 'deny'], true)) {
            Notification::make()->danger()->title('The selected access setting is invalid.')->send();

            return;
        }
        if ($definition['sensitive']) {
            $this->pendingEmployeeId = $employeeId;
            $this->pendingPermissionKey = $permissionKey;
            $this->pendingSetting = $setting;
            $this->pendingReason = '';
            $this->dispatch('open-modal', id: 'confirm-sensitive-permission');

            return;
        }
        $this->persistLegacyChange($employee, $permissionKey, $setting, null);
    }

    public function confirmSensitivePermissionChange(): void
    {
        abort_unless(static::canAccess(), 403);
        if ($this->pendingEmployeeId === null || $this->pendingPermissionKey === null || $this->pendingSetting === null) {
            return;
        }
        $employee = Employee::query()->findOrFail($this->pendingEmployeeId);
        if ($this->persistLegacyChange($employee, $this->pendingPermissionKey, $this->pendingSetting, $this->pendingReason)) {
            $this->pendingEmployeeId = null;
            $this->pendingPermissionKey = null;
            $this->pendingSetting = null;
            $this->pendingReason = '';
            $this->dispatch('close-modal', id: 'confirm-sensitive-permission');
        }
    }

    public function cancelSensitivePermissionChange(): void
    {
        $this->pendingEmployeeId = null;
        $this->pendingPermissionKey = null;
        $this->pendingSetting = null;
        $this->pendingReason = '';
        $this->dispatch('close-modal', id: 'confirm-sensitive-permission');
    }

    /** Legacy group links from module regressions and saved URLs remain functional. */
    public function selectGroup(string $group): void
    {
        $normalized = str($group)->lower()->replace([' / ', ' & ', ' '], '_')->toString();
        $aliases = [
            'orders' => 'sales_orders', 'purchasing' => 'purchasing',
            'products_costs' => 'products_inventory', 'responsibility' => 'products_inventory',
            'inventory_locations' => 'products_inventory', 'stock_transfers' => 'products_inventory',
        ];
        $this->group = $group;
        $this->groupFilter = $aliases[$normalized] ?? 'all';
        $this->moduleSearch = in_array($normalized, ['inventory_locations', 'stock_transfers'], true) ? $group : '';
    }

    /** Old matrix-column controls are harmless no-ops; the redesigned page has no horizontal columns. */
    public function toggleColumn(string $permissionKey): void {}

    public function showAllColumns(): void {}

    public function resetColumns(): void {}

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $employees = $this->employeeQuery()->paginate(12);
        if ($this->selectedEmployeeId === null && $employees->isNotEmpty()) {
            $this->selectedEmployeeId = (int) $employees->first()->getKey();
            $this->loadDraftSettings();
        }
        $selected = $this->selectedEmployeeOrNull();
        $registry = app(AccessControlModuleRegistry::class);

        return [
            'employees' => $employees,
            'selectedEmployee' => $selected,
            'groups' => $registry->groups(),
            'modulesByGroup' => $this->moduleRows($selected, $registry),
            'teams' => Team::query()->orderBy('name')->pluck('name', 'id'),
            'roleOptions' => collect(EmployeeRole::cases())->mapWithKeys(fn (EmployeeRole $role): array => [$role->value => $role->getLabel()]),
            'dirtyCount' => count($this->pendingChanges()),
            'hasFinancialChanges' => $this->hasFinancialChanges(),
            'reconciliation' => $registry->reconcile(),
        ];
    }

    public function pendingEmployeeName(): string
    {
        return $this->pendingEmployeeId ? (string) Employee::query()->whereKey($this->pendingEmployeeId)->value('name') : '';
    }

    public function pendingPermissionLabel(): string
    {
        return app(EmployeePermissionCatalog::class)->find((string) $this->pendingPermissionKey)['label'] ?? '';
    }

    public function pendingSettingLabel(): string
    {
        return match ($this->pendingSetting) {
            'allow' => 'Allow for Employee', 'deny' => 'Block for Employee', default => 'Reset to Role Default'
        };
    }

    public function pendingPermissionIsFinancial(): bool
    {
        return (bool) (app(EmployeePermissionCatalog::class)->find((string) $this->pendingPermissionKey)['financial'] ?? false);
    }

    private function employeeQuery(): Builder
    {
        return Employee::query()
            ->with(['user:id,name,email', 'team:id,name'])
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($this->role !== '', fn (Builder $query): Builder => $query->where('role', $this->role))
            ->when($this->team !== '', fn (Builder $query): Builder => $query->where('team_id', (int) $this->team))
            ->when($this->status === 'active', fn (Builder $query): Builder => $query->where('status', true))
            ->when($this->status === 'inactive', fn (Builder $query): Builder => $query->where('status', false))
            ->orderBy('name');
    }

    private function selectedEmployee(): Employee
    {
        return $this->selectedEmployeeOrNull() ?? abort(404);
    }

    private function selectedEmployeeOrNull(): ?Employee
    {
        return $this->selectedEmployeeId === null ? null : Employee::query()
            ->with(['user:id,name,email', 'team:id,name', 'permissionOverrides:id,employee_id,permission_key,effect'])
            ->find($this->selectedEmployeeId);
    }

    private function loadDraftSettings(): void
    {
        $employee = $this->selectedEmployeeOrNull();
        $overrides = $employee?->permissionOverrides?->keyBy('permission_key');
        $this->originalSettings = collect(app(AccessControlModuleRegistry::class)->managedPermissionKeys())
            ->mapWithKeys(fn (string $key): array => [$key => $overrides?->get($key)?->effect?->value ?? 'inherit'])
            ->all();
        $this->draftSettings = $this->originalSettings;
    }

    /** @return array<string, string> */
    private function pendingChanges(): array
    {
        return collect($this->draftSettings)
            ->filter(fn (string $setting, string $key): bool => ($this->originalSettings[$key] ?? 'inherit') !== $setting)
            ->all();
    }

    private function hasFinancialChanges(): bool
    {
        $catalog = app(EmployeePermissionCatalog::class);

        return collect(array_keys($this->pendingChanges()))
            ->contains(fn (string $key): bool => (bool) ($catalog->find($key)['financial'] ?? false));
    }

    private function authorizeManagedChange(Employee $employee, string $permissionKey, string $setting): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($setting, ['inherit', 'allow', 'deny'], true), 422);
        abort_unless(in_array($permissionKey, app(AccessControlModuleRegistry::class)->managedPermissionKeys(), true), 404);
        $actor = auth()->user();
        if (! $actor?->can('managePermissions', $employee)) {
            throw new AuthorizationException('This employee’s access is protected.');
        }
        $definition = app(EmployeePermissionCatalog::class)->find($permissionKey);
        if (($definition['financial'] ?? false) && $actor->employee?->role !== EmployeeRole::Owner) {
            throw new AuthorizationException('Only the Owner may change financial access.');
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function moduleRows(?Employee $employee, AccessControlModuleRegistry $registry): array
    {
        if ($employee === null) {
            return [];
        }
        $catalog = app(EmployeePermissionCatalog::class);
        $actor = auth()->user();
        $modules = collect($registry->modules())
            ->when($this->groupFilter !== 'all', fn ($items) => $items->where('group', $this->groupFilter))
            ->when(trim($this->moduleSearch) !== '', function ($items) {
                $search = strtolower(trim($this->moduleSearch));

                return $items->filter(function (array $module) use ($search): bool {
                    $labels = [...array_column($module['view_permissions'], 'label'), ...array_column($module['edit_permissions'], 'label'), ...array_column($module['advanced_permissions'], 'label')];

                    return str_contains(strtolower($module['label'].' '.$module['description'].' '.implode(' ', $labels)), $search);
                });
            })
            ->map(function (array $module) use ($employee, $catalog, $actor): array {
                $module['permissions'] = collect($module['permission_keys'])->mapWithKeys(function (string $key) use ($employee, $catalog, $actor): array {
                    $setting = $this->draftSettings[$key] ?? 'inherit';
                    $roleDefault = $catalog->roleDefault($employee, $key);
                    $effective = match ($setting) {
                        'allow' => true, 'deny' => false, default => $roleDefault
                    };
                    $definition = $catalog->find($key);
                    $canManage = $actor?->can('managePermissions', $employee) === true
                        && (! ($definition['financial'] ?? false) || $actor->employee?->role === EmployeeRole::Owner);

                    return [$key => [...$definition, 'setting' => $setting, 'original_setting' => $this->originalSettings[$key] ?? 'inherit', 'role_default' => $roleDefault, 'effective' => $effective, 'can_manage' => $canManage, 'changed' => ($this->originalSettings[$key] ?? 'inherit') !== $setting]];
                })->all();
                $module['primary_level'] = $this->primaryLevel($module);
                $module['role_level'] = $this->primaryLevel($module, true);
                $module['override_count'] = collect($module['permissions'])->where('setting', '!=', 'inherit')->count();
                $module['changed_count'] = collect($module['permissions'])->where('changed', true)->count();
                $module['can_manage_primary'] = collect([...$module['view_keys'], ...$module['edit_keys']])
                    ->every(fn (string $key): bool => $module['permissions'][$key]['can_manage'] ?? false);

                return $module;
            })
            ->when($this->overrideFilter === 'has_override', fn ($items) => $items->where('override_count', '>', 0))
            ->when($this->overrideFilter === 'allowed', fn ($items) => $items->filter(fn (array $module): bool => collect($module['permissions'])->contains(fn (array $permission): bool => $permission['setting'] === 'allow')))
            ->when($this->overrideFilter === 'denied', fn ($items) => $items->filter(fn (array $module): bool => collect($module['permissions'])->contains(fn (array $permission): bool => $permission['setting'] === 'deny')))
            ->when($this->overrideFilter === 'changed', fn ($items) => $items->where('changed_count', '>', 0));

        return $modules->groupBy('group')->all();
    }

    /** @param array<string, mixed> $module */
    private function primaryLevel(array $module, bool $roleOnly = false): string
    {
        if ($module['derived']) {
            return 'derived';
        }
        $value = fn (string $key): bool => $roleOnly ? (bool) ($module['permissions'][$key]['role_default'] ?? false) : (bool) ($module['permissions'][$key]['effective'] ?? false);
        $view = $module['view_keys'] === [] || collect($module['view_keys'])->every($value);
        $edit = $module['edit_keys'] !== [] && collect($module['edit_keys'])->every($value);

        return match (true) {
            $view && $edit => 'view_edit',
            $module['view_keys'] !== [] && $view => 'view',
            $module['view_keys'] === [] && $edit => 'view_edit',
            default => 'none',
        };
    }

    private function persistLegacyChange(Employee $employee, string $permissionKey, string $setting, ?string $reason): bool
    {
        try {
            app(EmployeePermissionOverrideService::class)->change($employee, $permissionKey, match ($setting) {
                'allow' => EmployeePermissionEffect::Allow, 'deny' => EmployeePermissionEffect::Deny, default => null,
            }, filled($reason) ? trim((string) $reason) : null, auth()->user());
            if ($employee->is($this->selectedEmployeeOrNull())) {
                $this->loadDraftSettings();
            }
            Notification::make()->success()->title('Access updated')->send();

            return true;
        } catch (ValidationException|AuthorizationException $exception) {
            $message = $exception instanceof ValidationException ? (collect($exception->errors())->flatten()->first() ?? 'The access change is invalid.') : ($exception->getMessage() ?: 'You are not authorized to make this access change.');
            Notification::make()->danger()->title($message)->send();

            return false;
        }
    }
}
