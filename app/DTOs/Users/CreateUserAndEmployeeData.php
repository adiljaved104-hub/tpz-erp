<?php

namespace App\DTOs\Users;

use App\DTOs\Employees\CreateEmployeeData;

final readonly class CreateUserAndEmployeeData
{
    public function __construct(
        public CreateUserAccountData $user,
        public CreateEmployeeData $employee,
    ) {}
}
