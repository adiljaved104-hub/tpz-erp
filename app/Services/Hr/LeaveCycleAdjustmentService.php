<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\HistoricalLeaveRecord;
use App\Models\LeaveCycleAdjustment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use App\Services\Leave\AnnualLeaveCycleResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveCycleAdjustmentService
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HistoricalLeaveAuthorization $access,
        private readonly AnnualLeaveCycleResolver $cycles,
        private readonly JoiningDateLeaveBalanceService $balances,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string,mixed> $input */
    public function record(array $input, User $actor): LeaveCycleAdjustment
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveHistoricalAdjust), AuthorizationException::class);
        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'leave_period_start' => ['required', 'date'],
            'leave_period_end' => ['required', 'date', 'after_or_equal:leave_period_start'],
            'adjustment_type' => ['required', 'in:credit,debit'],
            'days' => ['required', 'numeric', 'gt:0', 'max:366'],
            'reason' => ['required', 'string', 'max:2000'],
            'supporting_reference' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'confirmed' => ['required', 'accepted'],
        ])->validate();

        $employee = $this->access->employeeQuery($actor, HrPermission::LeaveHistoricalAdjust, excludeSelf: true)
            ->whereKey($data['employee_id'])->first();
        throw_unless($employee instanceof Employee, AuthorizationException::class);

        return DB::transaction(function () use ($data, $actor, $employee): LeaveCycleAdjustment {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            throw_unless($this->authorization->allows($actor, HrPermission::LeaveHistoricalAdjust)
                && $this->access->canRecordEmployee($actor, $locked, HrPermission::LeaveHistoricalAdjust), AuthorizationException::class);
            $replay = LeaveCycleAdjustment::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay instanceof LeaveCycleAdjustment) {
                $same = $replay->employee_id === $locked->id
                    && $replay->leave_period_start->toDateString() === $data['leave_period_start']
                    && $replay->leave_period_end->toDateString() === $data['leave_period_end']
                    && $replay->adjustment_type === $data['adjustment_type']
                    && bccomp((string) $replay->days, (string) $data['days'], 2) === 0
                    && $replay->supporting_reference === trim($data['supporting_reference'])
                    && $replay->reason === trim($data['reason']);
                if (! $same) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This confirmation key was already used for a different leave balance adjustment.']);
                }

                return $replay;
            }

            if ($locked->joining_date === null || $locked->joining_date_verified_at === null) {
                throw ValidationException::withMessages(['employee_id' => 'Verify the employee joining date before adjusting an anniversary balance.']);
            }
            $cycle = $this->cycles->forDate($locked, $data['leave_period_start']);
            if ($cycle->start->toDateString() !== $data['leave_period_start'] || $cycle->end->toDateString() !== $data['leave_period_end']) {
                throw ValidationException::withMessages(['leave_period_start' => 'The selected dates must exactly match one of this employee’s joining-anniversary leave cycles.']);
            }
            if ($cycle->start->gt(now()->toDateString())) {
                throw ValidationException::withMessages(['leave_period_start' => 'Opening balance adjustments cannot be recorded for a future leave cycle.']);
            }
            if (LeaveCycleAdjustment::query()->where('employee_id', $locked->id)
                ->whereDate('leave_period_start', $cycle->start->toDateString())
                ->where('supporting_reference', trim($data['supporting_reference']))->exists()) {
                throw ValidationException::withMessages(['supporting_reference' => 'This supporting reference has already been used for the employee’s leave cycle.']);
            }
            if (HistoricalLeaveRecord::query()->where('employee_id', $locked->id)
                ->whereDate('leave_period_start', $cycle->start->toDateString())->where('source', 'import')->exists()) {
                throw ValidationException::withMessages(['leave_period_start' => 'Historical leave has already been imported for this cycle. Use the historical source rows as the balance evidence instead of adding an opening adjustment.']);
            }

            $balance = $this->balances->forDate($locked, $cycle->start);
            if ($data['adjustment_type'] === 'debit'
                && bccomp((string) $data['days'], $balance->availableAfterPending, 2) > 0) {
                throw ValidationException::withMessages(['days' => 'A debit cannot reduce this leave cycle below zero available days after pending leave.']);
            }

            $adjustment = LeaveCycleAdjustment::query()->create([
                'reference' => $this->reference(),
                'employee_id' => $locked->id,
                'leave_period_start' => $cycle->start->toDateString(),
                'leave_period_end' => $cycle->end->toDateString(),
                'adjustment_type' => $data['adjustment_type'],
                'days' => $data['days'],
                'reason' => trim($data['reason']),
                'supporting_reference' => trim($data['supporting_reference']),
                'recorded_by_user_id' => $actor->id,
                'recorded_at' => now(),
                'idempotency_key' => $data['idempotency_key'],
            ]);
            $this->activity->log('hr.leave_cycle_balance_adjusted', $actor, $adjustment, [
                'reference' => $adjustment->reference,
                'employee_id' => $locked->id,
                'leave_period_start' => $cycle->start->toDateString(),
                'leave_period_end' => $cycle->end->toDateString(),
                'adjustment_type' => $adjustment->adjustment_type,
                'days' => (string) $adjustment->days,
                'supporting_reference' => $adjustment->supporting_reference,
            ]);

            return $adjustment;
        }, 3);
    }

    private function reference(): string
    {
        do {
            $reference = 'LCA-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (LeaveCycleAdjustment::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
