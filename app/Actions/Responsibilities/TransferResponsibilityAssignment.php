<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAssignmentService;

class TransferResponsibilityAssignment
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly ResponsibilityAssignmentService $service) {}

    public function handle(ResponsibilityAssignment $assignment, TransferResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $assignment);

        return $this->service->transfer($assignment, $data, $actor);
    }
}
