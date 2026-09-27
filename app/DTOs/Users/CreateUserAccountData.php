<?php

namespace App\DTOs\Users;

use SensitiveParameter;

final readonly class CreateUserAccountData
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter]
        public string $password,
    ) {}
}
