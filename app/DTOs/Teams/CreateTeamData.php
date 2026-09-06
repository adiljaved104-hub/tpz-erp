<?php

namespace App\DTOs\Teams;

final readonly class CreateTeamData
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public bool $status = true,
    ) {}
}
