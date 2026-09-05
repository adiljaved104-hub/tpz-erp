<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAssignmentService;

class ChangeResponsibilityQuantity
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly ResponsibilityAssignmentService $service) {}

    public function handle(ResponsibilityAssignment $assignment, ChangeResponsibilityQuantityData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $assignment);

        return $this->service->changeQuantity($assignment, $data, $actor);
    }
}
