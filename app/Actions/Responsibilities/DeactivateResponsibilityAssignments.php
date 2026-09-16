<?php

namespace App\Actions\Responsibilities;

use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeactivateResponsibilityAssignments
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ResponsibilityAssignmentService $service,
    ) {}

    /** @param  iterable<int, ResponsibilityAssignment|int>  $assignments
     * @return Collection<int, ResponsibilityAssignment>
     */
    public function handle(iterable $assignments, DeactivateResponsibilityAssignmentData $data, User $actor): Collection
    {
        $ids = collect($assignments)
            ->map(fn (ResponsibilityAssignment|int $assignment): int => $assignment instanceof ResponsibilityAssignment ? $assignment->id : $assignment)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['reason' => 'Select at least one Responsibility Assignment.']);
        }

        return DB::transaction(function () use ($ids, $data, $actor): Collection {
            $locked = ResponsibilityAssignment::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($locked->count() !== $ids->count()) {
                throw ValidationException::withMessages(['reason' => 'One or more selected Responsibility Assignments no longer exist.']);
            }

            $locked->each(fn (ResponsibilityAssignment $assignment) => $this->authorization->authorize(
                $actor,
                ResponsibilityPermission::Deactivate,
                $assignment,
            ));

            return $this->service->deactivateLockedBatch($locked, $data, $actor);
        });
    }
}
