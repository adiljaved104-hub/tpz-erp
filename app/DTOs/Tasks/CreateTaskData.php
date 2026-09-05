<?php

namespace App\DTOs\Tasks;

use App\Enums\TaskAssignmentMode;
use App\Enums\TaskLinkedType;
use App\Enums\TaskPriority;

final readonly class CreateTaskData
{
    public function __construct(
        public string $title,
        public ?string $description,
        public TaskPriority $priority,
        public ?int $assignedEmployeeId,
        public ?int $assignedTeamId,
        public ?string $dueAt,
        public ?string $followUpAt,
        public ?TaskLinkedType $linkedType,
        public ?int $linkedRecordId,
        public string $idempotencyKey,
        /** @var array<int, int> */
        public array $assignedEmployeeIds = [],
        public ?TaskAssignmentMode $assignmentMode = null,
    ) {}
}
