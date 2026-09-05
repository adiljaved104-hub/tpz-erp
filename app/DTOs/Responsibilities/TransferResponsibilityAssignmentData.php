<?php

namespace App\DTOs\Responsibilities;

readonly class TransferResponsibilityAssignmentData
{
    public function __construct(public int $employeeId, public string $reason, public string $idempotencyKey) {}
}
