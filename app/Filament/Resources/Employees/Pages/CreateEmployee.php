<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Actions\Users\CreateUserAndEmployee as CreateUserAndEmployeeAction;
use App\DTOs\Employees\CreateEmployeeData;
use App\DTOs\Users\CreateUserAccountData;
use App\DTOs\Users\CreateUserAndEmployeeData;
use App\Enums\EmployeeRole;
use App\Filament\Resources\Employees\EmployeeResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $role = $data['role'] instanceof EmployeeRole
            ? $data['role']
            : EmployeeRole::from($data['role']);

        return app(CreateUserAndEmployeeAction::class)->handle(new CreateUserAndEmployeeData(
            user: new CreateUserAccountData($data['name'], $data['email'], $data['password']),
            employee: new CreateEmployeeData(
                userId: 0,
                name: $data['name'],
                email: $data['email'],
                designation: $data['designation'],
                role: $role,
                status: (bool) $data['status'],
                phone: $data['phone'] ?? null,
                teamId: isset($data['team_id']) ? (int) $data['team_id'] : null,
                joiningDate: filled($data['joining_date'] ?? null) ? CarbonImmutable::parse($data['joining_date']) : null,
            ),
        ), auth()->user());
    }
}
