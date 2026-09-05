<?php

namespace App\Actions\Employees;

use App\DTOs\Employees\CreateEmployeeData;
use App\Enums\EmployeeRole;
use App\Exceptions\EmployeeLinkConflictException;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmployeeAccessService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BootstrapInitialOwner
{
    public const OWNER_NAME = 'Adil Hussain';

    public const OWNER_EMAIL = 'adiljaved104@gmail.com';

    public function __construct(
        private readonly CreateEmployee $employees,
        private readonly ActivityLogger $activity,
        private readonly ReferenceSequenceService $references,
    ) {}

    public function handle(): Employee
    {
        $approvedUser = User::query()->find(EmployeeAccessService::INITIAL_OWNER_USER_ID);

        if ($approvedUser === null || $approvedUser->name !== self::OWNER_NAME || mb_strtolower($approvedUser->email) !== self::OWNER_EMAIL) {
            throw new RuntimeException('Approved Owner User ID 1 does not match the approved name and email.');
        }

        if ($approvedUser->employee !== null) {
            if ($approvedUser->employee->role !== EmployeeRole::Owner) {
                throw new EmployeeLinkConflictException('User ID 1 is already linked to a non-Owner Employee.');
            }

            return $approvedUser->employee;
        }

        $reservedReference = $this->references->nextEmployeeReference();

        return DB::transaction(function () use ($reservedReference): Employee {
            $user = User::query()->lockForUpdate()->find(EmployeeAccessService::INITIAL_OWNER_USER_ID);

            if ($user === null || $user->name !== self::OWNER_NAME || mb_strtolower($user->email) !== self::OWNER_EMAIL) {
                throw new RuntimeException('Approved Owner User ID 1 does not match the approved name and email.');
            }

            if ($user->employee !== null) {
                if ($user->employee->role !== EmployeeRole::Owner) {
                    throw new EmployeeLinkConflictException('User ID 1 is already linked to a non-Owner Employee.');
                }

                return $user->employee;
            }

            if (Employee::query()->whereRaw('LOWER(email) = ?', [self::OWNER_EMAIL])->exists()) {
                throw new EmployeeLinkConflictException('An Employee already uses the approved Owner email; bootstrap aborted.');
            }

            $employee = $this->employees->create(new CreateEmployeeData(
                userId: $user->getKey(),
                name: self::OWNER_NAME,
                email: self::OWNER_EMAIL,
                designation: 'Owner / Managing Director',
                role: EmployeeRole::Owner,
                status: true,
            ), $user, $reservedReference);

            $this->activity->log('owner.bootstrap.completed', $user, $user, [
                'employee_id' => $employee->getKey(),
                'linked_user_id' => $user->getKey(),
            ]);

            return $employee;
        });
    }
}
