<?php

namespace App\DTOs\Employees;

use App\Enums\EmployeeRole;
use Carbon\CarbonImmutable;

final readonly class CreateEmployeeData
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $designation,
        public EmployeeRole $role = EmployeeRole::Staff,
        public bool $status = true,
        public ?string $phone = null,
        public ?int $teamId = null,
        public ?CarbonImmutable $joiningDate = null,
    ) {}
}
