<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\ChangeResponsibilityScopeBatchData;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Support\Collection;

class ChangeResponsibilityScope
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ResponsibilityAssignmentService $service,
    ) {}

    public function handle(ResponsibilityAssignment $assignment, ChangeResponsibilityScopeData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $assignment);

        return $this->service->changeScope($assignment, $data, $actor);
    }

    /** @return Collection<int, ResponsibilityAssignment> */
    public function handleBatch(ResponsibilityAssignment $assignment, ChangeResponsibilityScopeBatchData $data, User $actor): Collection
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $assignment);

        return $this->service->changeScopes($assignment, $data, $actor);
    }
}
