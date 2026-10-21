<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeavePolicyEvent;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeavePolicyVersioningService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly ActivityLogger $activity,
        private readonly HistoricalLeaveActivationService $historicalActivation,
    ) {}

    /** @return array{employees_in_mid_cycle:int,unresolved_leap_day_rule:int,effective_date:string} */
    public function transitionImpact(string $effectiveDate, User $actor): array
    {
        $this->authorizeManagement($actor);
        $date = CarbonImmutable::parse($effectiveDate)->startOfDay();
        $midCycle = 0;
        $unresolved = 0;

        Employee::query()->where('status', true)->whereNotNull('joining_date')->whereNotNull('joining_date_verified_at')
            ->whereDate('joining_date', '<=', $date->toDateString())
            ->chunkById(200, function ($employees) use ($date, &$midCycle, &$unresolved): void {
                foreach ($employees as $employee) {
                    try {
                        $cycle = $this->cycles->forDate($employee, $date);
                        if ($cycle->start->lt($date) && $cycle->end->gte($date)) {
                            $midCycle++;
                        }
                    } catch (ValidationException) {
                        $unresolved++;
                    }
                }
            });

        return ['employees_in_mid_cycle' => $midCycle, 'unresolved_leap_day_rule' => $unresolved, 'effective_date' => $date->toDateString()];
    }

    /** @param array<string, mixed> $data */
    public function createVersion(array $data, User $actor): LeavePolicy
    {
        $this->authorizeManagement($actor);
        $data['status'] ??= 'draft';
        $data['activation_confirmed'] ??= false;
        $validated = Validator::make($data, [
            'annual_entitlement_days' => ['required', 'numeric', 'min:0', 'max:366'],
            'carry_forward_enabled' => ['required', 'boolean'],
            'maximum_carry_forward_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'carry_forward_expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
            'description' => ['required', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'max:2000'],
            'cycle_transition_decision' => ['nullable', 'in:apply_full_entitlement,next_anniversary'],
            'status' => ['required', 'in:active,draft'],
            'activation_confirmed' => ['required', 'boolean'],
        ])->validate();
        if ($validated['status'] === 'active' && ! $validated['activation_confirmed']) {
            throw ValidationException::withMessages(['activation_confirmed' => 'Confirm activation before replacing the applicable policy.']);
        }
        if ($validated['status'] === 'active' && ! $this->historicalActivation->isActive()) {
            throw ValidationException::withMessages(['status' => 'Complete Owner/Admin historical leave activation before applying an anniversary policy.']);
        }
        $effective = CarbonImmutable::parse($validated['effective_date'])->startOfDay();
        $impact = $this->transitionImpact($effective->toDateString(), $actor);
        if ($impact['unresolved_leap_day_rule'] > 0) {
            throw ValidationException::withMessages(['effective_date' => 'At least one verified 29 February joining date needs an Owner-approved anniversary rule before a policy transition can be previewed safely.']);
        }
        if ($impact['employees_in_mid_cycle'] > 0 && $validated['cycle_transition_decision'] === null) {
            throw ValidationException::withMessages(['cycle_transition_decision' => 'Choose how the new entitlement applies to employees already inside a leave cycle. No mid-cycle behavior is assumed.']);
        }
        if (! $validated['carry_forward_enabled']) {
            $validated['maximum_carry_forward_days'] = '0.00';
            $validated['carry_forward_expiry_months'] = null;
        } elseif (blank($validated['maximum_carry_forward_days']) || $validated['carry_forward_expiry_months'] === null) {
            throw ValidationException::withMessages(['carry_forward_expiry_months' => 'A maximum carry-forward amount and expiry period are required when carry-forward is enabled.']);
        }

        return DB::transaction(function () use ($validated, $effective, $actor): LeavePolicy {
            $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->lockForUpdate()->latest('effective_from')->firstOrFail();
            if ($effective->lte($current->effective_from)) {
                throw ValidationException::withMessages(['effective_date' => 'The new policy must take effect after the current version begins. Previous policy versions cannot be overwritten.']);
            }
            if (LeavePolicy::query()->whereDate('effective_from', $effective->toDateString())->exists()) {
                throw ValidationException::withMessages(['effective_date' => 'A policy version already exists for this date.']);
            }
            $previous = $this->snapshot($current);
            $activating = $validated['status'] === 'active';
            if ($activating) {
                $current->forceFill(['effective_to' => $effective->subDay()->toDateString()])->save();
            }
            $policy = LeavePolicy::query()->create([
                'name' => 'Annual Leave Policy — '.$effective->format('d M Y'),
                'annual_leave_entitlement_days' => $validated['annual_entitlement_days'],
                'leave_year_mode' => $current->leave_year_mode,
                'leave_year_basis' => 'joining_anniversary',
                'monthly_accrual_enabled' => false,
                'carry_forward_enabled' => (bool) $validated['carry_forward_enabled'],
                'maximum_carry_forward_days' => $validated['maximum_carry_forward_days'] ?? '0.00',
                'carry_forward_expiry_months' => $validated['carry_forward_expiry_months'],
                'policy_description' => trim($validated['description']),
                'policy_change_reason' => trim($validated['reason']),
                'cycle_transition_decision' => $validated['cycle_transition_decision'] ?? 'cycle_start_only',
                'half_day_leave_enabled' => $current->half_day_leave_enabled,
                'manager_approval_enabled' => $current->manager_approval_enabled,
                'compensatory_off_requires_approval' => $current->compensatory_off_requires_approval,
                'compensatory_off_expiry_days' => $current->compensatory_off_expiry_days,
                'scheduled_sunday_compensatory_off_enabled' => $current->scheduled_sunday_compensatory_off_enabled,
                'effective_from' => $effective->toDateString(), 'effective_to' => null, 'status' => $activating,
                'created_by_user_id' => $actor->id,
            ]);
            $newValues = $this->snapshot($policy);
            LeavePolicyEvent::query()->create([
                'leave_policy_id' => $policy->id, 'actor_user_id' => $actor->id, 'event_type' => $activating ? 'version_created' : 'draft_created',
                'effective_date' => $effective->toDateString(), 'previous_values' => $previous,
                'new_values' => $newValues, 'reason' => trim($validated['reason']), 'occurred_at' => now(),
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->activity->log($activating ? 'hr.leave_policy_version_created' : 'hr.leave_policy_draft_created', $actor, $policy, [
                'effective_date' => $effective->toDateString(), 'previous_policy_id' => $current->id,
                'transition_decision' => $policy->cycle_transition_decision,
                'status' => $activating ? 'active' : 'draft',
            ]);

            return $policy;
        });
    }

    public function activateDraft(int $policyId, User $actor, bool $confirmed): LeavePolicy
    {
        $this->authorizeManagement($actor);
        if (! $confirmed) {
            throw ValidationException::withMessages(['activation_confirmed' => 'Confirm activation before replacing the applicable policy.']);
        }
        if (! $this->historicalActivation->isActive()) {
            throw ValidationException::withMessages(['policy' => 'Complete Owner/Admin historical leave activation before applying this Draft.']);
        }

        return DB::transaction(function () use ($policyId, $actor): LeavePolicy {
            $draft = LeavePolicy::query()->whereKey($policyId)->lockForUpdate()->firstOrFail();
            if ($draft->status || $draft->effective_to !== null) {
                throw ValidationException::withMessages(['policy' => 'Only an unapplied Draft policy can be activated.']);
            }
            $effective = CarbonImmutable::parse($draft->effective_from)->startOfDay();
            if ($effective->lt(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['effective_date' => 'A Draft cannot be activated retroactively. Choose a future effective date by creating a new Draft.']);
            }

            $current = LeavePolicy::query()->where('status', true)->whereNull('effective_to')->lockForUpdate()->latest('effective_from')->firstOrFail();
            if ($effective->lte($current->effective_from)) {
                throw ValidationException::withMessages(['effective_date' => 'The Draft must take effect after the currently active policy begins.']);
            }
            if (LeavePolicy::query()->where('status', true)->where('id', '!=', $current->id)
                ->whereDate('effective_from', '>=', $effective->toDateString())->exists()) {
                throw ValidationException::withMessages(['effective_date' => 'An active policy is already scheduled on or after this Draft date. Create a Draft after the latest scheduled active version.']);
            }

            $impact = $this->transitionImpact($effective->toDateString(), $actor);
            if ($impact['unresolved_leap_day_rule'] > 0) {
                throw ValidationException::withMessages(['effective_date' => 'A verified 29 February joining date needs an Owner-approved anniversary rule before this Draft can be activated.']);
            }
            if ($impact['employees_in_mid_cycle'] > 0 && ! in_array($draft->cycle_transition_decision, ['apply_full_entitlement', 'next_anniversary'], true)) {
                throw ValidationException::withMessages(['cycle_transition_decision' => 'The Draft has no explicit transition decision for employees already inside an anniversary cycle.']);
            }

            $previous = $this->snapshot($current);
            $current->forceFill(['effective_to' => $effective->subDay()->toDateString()])->save();
            $draft->forceFill(['status' => true])->save();
            $newValues = $this->snapshot($draft->fresh());
            LeavePolicyEvent::query()->create([
                'leave_policy_id' => $draft->id, 'actor_user_id' => $actor->id, 'event_type' => 'draft_activated',
                'effective_date' => $effective->toDateString(), 'previous_values' => $previous,
                'new_values' => $newValues, 'reason' => 'Draft activated: '.($draft->policy_change_reason ?: $draft->policy_description),
                'occurred_at' => now(), 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->activity->log('hr.leave_policy_draft_activated', $actor, $draft, [
                'effective_date' => $effective->toDateString(), 'previous_policy_id' => $current->id,
                'transition_decision' => $draft->cycle_transition_decision,
            ]);

            return $draft->fresh();
        });
    }

    private function authorizeManagement(User $actor): void
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveManagePolicy), AuthorizationException::class);
    }

    /** @return array<string, mixed> */
    private function snapshot(LeavePolicy $policy): array
    {
        return [
            'annual_entitlement_days' => (string) $policy->annual_leave_entitlement_days,
            'leave_year_basis' => $policy->leave_year_basis,
            'monthly_accrual_enabled' => (bool) $policy->monthly_accrual_enabled,
            'carry_forward_enabled' => (bool) $policy->carry_forward_enabled,
            'maximum_carry_forward_days' => (string) $policy->maximum_carry_forward_days,
            'carry_forward_expiry_months' => $policy->carry_forward_expiry_months,
            'effective_from' => $policy->effective_from->toDateString(),
            'effective_to' => $policy->effective_to?->toDateString(),
            'status' => $policy->status ? 'active' : 'draft',
            'transition_decision' => $policy->cycle_transition_decision,
        ];
    }
}
