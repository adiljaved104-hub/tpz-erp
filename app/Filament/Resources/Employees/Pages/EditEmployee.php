<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Actions\Employees\ChangeEmployeeRole;
use App\Actions\Employees\SetEmployeeStatus;
use App\Enums\EmployeeRole;
use App\Filament\Pages\ChangeLoginEmail;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use App\Services\ActivityLogger;
use App\Services\LoginEmailChangeService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeLoginEmail')
                ->label('Change Login Email')
                ->icon('heroicon-o-envelope')
                ->url(fn (): string => ChangeLoginEmail::getUrl(['employee' => $this->getRecord()->id]))
                ->visible(fn (): bool => Schema::hasTable('login_email_change_requests') && $this->getRecord()->user !== null
                    && app(LoginEmailChangeService::class)->allows(auth()->user(), $this->getRecord()->user)),
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Employee $record */
        return DB::transaction(function () use ($record, $data): Employee {
            $actor = auth()->user();

            if ($actor === null || ! $actor->can('update', $record)) {
                throw new AuthorizationException;
            }

            $submittedUserId = $data['user_id'] ?? $record->user_id;

            Validator::make(
                [
                    'user_id' => $submittedUserId,
                    'email' => $data['email'] ?? $record->email,
                ],
                [
                    'user_id' => [
                        'nullable',
                        'integer',
                        'exists:users,id',
                        function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                            if ((int) $value !== (int) $record->user_id) {
                                $fail('The Login Account cannot be changed from the Employee edit form.');
                            }
                        },
                    ],
                    'email' => [
                        'required',
                        function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                            if ($value !== $record->email) {
                                $fail('The Login Email cannot be changed from the Employee edit form.');
                            }
                        },
                    ],
                ],
            )->validate();

            $newRole = $data['role'] instanceof EmployeeRole
                ? $data['role']
                : EmployeeRole::from($data['role']);
            $newStatus = (bool) $data['status'];
            $profileData = Arr::only($data, [
                'name',
                'phone',
                'team_id',
                'designation',
                'joining_date',
            ]);
            $record->fill($profileData);
            $changedFields = array_keys($record->getDirty());
            $record->save();

            if ($record->role !== $newRole) {
                $record = app(ChangeEmployeeRole::class)->handle($record, $newRole, $actor);
            }

            if ($record->status !== $newStatus) {
                $record = app(SetEmployeeStatus::class)->handle($record, $newStatus, $actor);
            }

            if ($changedFields !== []) {
                app(ActivityLogger::class)->log('employee.profile_updated', $actor, $record, [
                    'changed_fields' => $changedFields,
                ]);
            }

            return $record;
        });
    }
}
