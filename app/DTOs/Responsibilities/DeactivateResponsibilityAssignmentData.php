<?php

namespace App\DTOs\Responsibilities;

readonly class DeactivateResponsibilityAssignmentData
{
    public function __construct(public string $reason) {}
}
