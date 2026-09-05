<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Enums\EmployeePermissionEffect;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\Authorization\EmployeePermissionCatalog;
use App\Services\Authorization\EmployeePermissionOverrideService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageEmployeeAccess extends Page
{
    use InteractsWithRecord;

    protected static string $resource = EmployeeResource::class;

    protected string $view = 'filament.resources.employees.pages.manage-employee-access';

    /** @var array<string, array{effect: string, reason: string}> */
    public array $changes = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->record->loadMissing(['user', 'permissionOverrides']);

        foreach (app(EmployeePermissionCatalog::class)->groups() as $permissions) {
            foreach ($permissions as $permission) {
                $override = $this->record->permissionOverrides->firstWhere('permission_key', $permission['key']);
                $this->changes[$this->token($permission['key'])] = [
                    'effect' => $override?->effect?->value ?? 'inherit',
                    'reason' => '',
                ];
            }
        }
    }

    public function hydrate(): void
    {
        $this->authorizeAccess();
    }

    public function savePermission(string $permissionKey): void
    {
        $this->authorizeAccess();
        $token = $this->token($permissionKey);
        $state = $this->changes[$token] ?? [];

        try {
            $effect = match ($state['effect'] ?? 'inherit') {
                'allow' => EmployeePermissionEffect::Allow,
                'deny' => EmployeePermissionEffect::Deny,
                'inherit' => null,
                default => throw ValidationException::withMessages(['effect' => 'Choose Inherit, Allow, or Deny.']),
            };

            app(EmployeePermissionOverrideService::class)->change(
                $this->getRecord(),
                $permissionKey,
                $effect,
                $state['reason'] ?? null,
                auth()->user(),
            );
            $this->record->unsetRelation('permissionOverrides');
            $this->record->load('permissionOverrides');
            $this->changes[$token]['reason'] = '';
            Notification::make()->success()->title('Employee access updated')->send();
        } catch (ValidationException|AuthorizationException $exception) {
            $message = $exception instanceof ValidationException
                ? (collect($exception->errors())->flatten()->first() ?? 'The permission change is invalid.')
                : ($exception->getMessage() ?: 'You are not authorized to make this access change.');
            $this->restorePermissionState($permissionKey);
            $this->addError("changes.{$token}.reason", $message);
            Notification::make()->danger()->title($message)->send();
        } catch (Throwable $exception) {
            report($exception);
            $message = 'The access change could not be saved. No change was applied.';
            $this->restorePermissionState($permissionKey);
            $this->addError("changes.{$token}.reason", $message);
            Notification::make()->danger()->title($message)->send();
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function getPermissionGroups(): array
    {
        /** @var Employee $employee */
        $employee = $this->getRecord();
        $catalog = app(EmployeePermissionCatalog::class);
        $overrides = $employee->permissionOverrides->keyBy('permission_key');

        return collect($catalog->groups())->map(fn (array $permissions): array => array_map(
            function (array $permission) use ($employee, $catalog, $overrides): array {
                $roleDefault = $catalog->roleDefault($employee, $permission['key']);
                $override = $overrides->get($permission['key'])?->effect?->value ?? 'inherit';

                return $permission + [
                    'role_default' => $roleDefault,
                    'override' => $override,
                    'effective' => $override === 'inherit' ? $roleDefault : $override === 'allow',
                    'token' => $this->token($permission['key']),
                ];
            },
            $permissions,
        ))->all();
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('managePermissions', $this->getRecord()), 403);
    }

    private function token(string $permissionKey): string
    {
        return sha1($permissionKey);
    }

    private function restorePermissionState(string $permissionKey): void
    {
        $this->record->unsetRelation('permissionOverrides');
        $this->record->load('permissionOverrides');
        $override = $this->record->permissionOverrides->firstWhere('permission_key', $permissionKey);
        $this->changes[$this->token($permissionKey)] = [
            'effect' => $override?->effect?->value ?? 'inherit',
            'reason' => '',
        ];
    }
}
