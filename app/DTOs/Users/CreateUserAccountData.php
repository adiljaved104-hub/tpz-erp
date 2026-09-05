<?php

namespace App\DTOs\Users;

final readonly class CreateUserAccountData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}
}
