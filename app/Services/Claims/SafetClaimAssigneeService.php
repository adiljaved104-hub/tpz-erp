<?php

namespace App\Services\Claims;

use App\Enums\SafetClaimPermission;
use App\Exceptions\SafetClaimException;
use App\Models\Employee;
use App\Models\SafetClaim;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SafetClaimAssigneeService
{
    public function __construct(
        private readonly SafetClaimAuthorization $authorization,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return Collection<int, User> */
    public function eligibleUsers(SafetClaim $claim): Collection
    {
        $claim->loadMissing('damagedStockEvent');

        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')->get()
            ->pluck('user')->filter(fn (?User $user): bool => $user !== null && $this->isEligible($claim, $user))->values();
    }

    /** @return array<int, string> */
    public function options(SafetClaim $claim): array
    {
        $users = $this->eligibleUsers($claim);
        if ($claim->assigned_to_user_id !== null && ! $users->contains('id', $claim->assigned_to_user_id)) {
            $current = User::query()->find($claim->assigned_to_user_id);
            if ($current !== null) {
                $users->push($current);
            }
        }

        return $users->mapWithKeys(fn (User $user): array => [
            $user->id => $user->name.' — '.$user->email,
        ])->all();
    }

    public function assign(SafetClaim $claim, ?int $userId, User $actor): SafetClaim
    {
        $this->authorization->authorize($actor, SafetClaimPermission::Assign, $claim);

        return DB::transaction(function () use ($claim, $userId, $actor): SafetClaim {
            $locked = SafetClaim::query()->with('damagedStockEvent')->lockForUpdate()->findOrFail($claim->id);
            $previous = $locked->assigned_to_user_id;
            if ($previous === $userId) {
                return $locked;
            }
            if ($userId !== null) {
                $assignee = User::query()->with('employee')->findOrFail($userId);
                if (! $this->isEligible($locked, $assignee)) {
                    throw new SafetClaimException('This employee is not eligible for this Claim.');
                }
            }

            $locked->forceFill(['assigned_to_user_id' => $userId])->save();
            $event = $previous === null && $userId !== null ? 'claim.assigned' : 'claim.reassigned';
            $this->activity->log($event, $actor, $locked, [
                'claim_reference' => $locked->reference,
                'previous_assignee_user_id' => $previous,
                'new_assignee_user_id' => $userId,
            ]);

            return $locked->refresh();
        });
    }

    public function autoAssign(SafetClaim $claim, User $actor): SafetClaim
    {
        $eligible = $this->eligibleUsers($claim);
        if ($eligible->count() !== 1) {
            return $claim;
        }

        $assignee = $eligible->sole();
        $claim->forceFill(['assigned_to_user_id' => $assignee->id])->save();
        $this->activity->log('claim.assigned', $actor, $claim, [
            'claim_reference' => $claim->reference,
            'previous_assignee_user_id' => null,
            'new_assignee_user_id' => $assignee->id,
        ]);

        return $claim->refresh();
    }

    private function isEligible(SafetClaim $claim, User $user): bool
    {
        if ($user->employee?->status !== true
            || ! $this->authorization->allows($user, SafetClaimPermission::View, $claim)
            || ! collect([SafetClaimPermission::File, SafetClaimPermission::UpdateStatus, SafetClaimPermission::Close])
                ->contains(fn (SafetClaimPermission $permission): bool => $this->authorization->allows($user, $permission, $claim))) {
            return false;
        }

        return $this->responsibilities->hasMatchingActiveResponsibility(
            $user,
            $claim->product_id,
            $claim->marketplace_platform_id,
            $claim->damagedStockEvent->warehouse_id,
        );
    }
}
