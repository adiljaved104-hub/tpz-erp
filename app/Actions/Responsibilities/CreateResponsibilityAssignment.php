<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAssignmentService;

class CreateResponsibilityAssignment
{
    public function __construct(private readonly ResponsibilityAuthorization $authorization, private readonly ResponsibilityAssignmentService $service) {}

    public function handle(CreateResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Assign);

        return $this->service->create($data, $actor);
    }
}
