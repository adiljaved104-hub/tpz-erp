<?php

namespace App\DTOs\Employees;

final readonly class LinkUserToEmployeeData
{
    public function __construct(
        public int $employeeId,
        public int $userId,
    ) {}
}
