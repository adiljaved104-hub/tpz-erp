<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Enums\WarningLevel;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\HrAcknowledgment;
use App\Models\User;
use App\Models\WarningCategory;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Notifications\HrRecordNotificationDispatcher;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeWarningService
{
    public function __construct(
        private readonly HrRecordAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly HrRecordNotificationDispatcher $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function issue(array $data, User $actor): EmployeeWarning
    {
        $validated = validator($data, [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'warning_level' => ['required', Rule::enum(WarningLevel::class)],
            'warning_category_id' => ['required', 'integer', 'exists:warning_categories,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'issued_date' => ['required', 'date', 'before_or_equal:today'],
            'acknowledgment_required' => ['required', 'boolean'],
        ])->validate();
        $employee = Employee::query()->findOrFail($validated['employee_id']);
        throw_unless($this->authorization->canIssueWarning($actor, $employee), AuthorizationException::class);
        if (! WarningCategory::query()->whereKey($validated['warning_category_id'])->where('status', true)->exists()) {
            throw ValidationException::withMessages(['warning_category_id' => 'Select an active Warning Category.']);
        }

        $reference = $this->references->nextEmployeeWarningReference((int) date('Y', strtotime($validated['issued_date'])));
        $warning = DB::transaction(function () use ($validated, $employee, $actor, $reference): EmployeeWarning {
            $warning = EmployeeWarning::query()->create($validated + [
                'reference' => $reference,
                'employee_id' => $employee->id,
                'issued_by_user_id' => $actor->id,
                'status' => 'active',
            ]);
            HrAcknowledgment::query()->create([
                'employee_warning_id' => $warning->id,
                'employee_id' => $employee->id,
            ]);
            $this->activity->log('warning.issued', $actor, $warning, [
                'warning_id' => $warning->id,
                'employee_id' => $employee->id,
                'warning_level' => $warning->warning_level->value,
                'acknowledgment_required' => $warning->acknowledgment_required,
            ]);

            return $warning;
        });
        $this->notifications->warningIssued($warning);

        return $warning->load(['employee', 'category', 'issuedBy', 'acknowledgments']);
    }

    public function markRead(EmployeeWarning $warning, User $actor): void
    {
        throw_unless($this->authorization->canViewWarning($actor, $warning), AuthorizationException::class);
        if ($actor->employee?->id !== $warning->employee_id) {
            return;
        }
        HrAcknowledgment::query()->where('employee_warning_id', $warning->id)
            ->where('employee_id', $actor->employee->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function acknowledge(EmployeeWarning $warning, User $actor): EmployeeWarning
    {
        throw_unless($this->authorization->canViewWarning($actor, $warning)
            && $actor->employee?->id === $warning->employee_id, AuthorizationException::class);
        if (! $warning->acknowledgment_required) {
            throw ValidationException::withMessages(['acknowledgment' => 'This Warning does not require acknowledgment.']);
        }

        DB::transaction(function () use ($warning, $actor): void {
            $locked = EmployeeWarning::query()->with('category')->lockForUpdate()->findOrFail($warning->id);
            $acknowledgment = HrAcknowledgment::query()->where('employee_warning_id', $locked->id)
                ->where('employee_id', $actor->employee->id)->lockForUpdate()->firstOrFail();
            if ($acknowledgment->acknowledged_at !== null) {
                throw ValidationException::withMessages(['acknowledgment' => 'This Warning is already acknowledged.']);
            }
            $snapshot = json_encode([
                'reference' => $locked->reference,
                'employee_id' => $locked->employee_id,
                'warning_level' => $locked->warning_level->value,
                'category' => $locked->category->name,
                'title' => $locked->title,
                'description' => $locked->description,
                'issued_date' => $locked->issued_date->toDateString(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $acknowledgment->forceFill([
                'read_at' => $acknowledgment->read_at ?? now(),
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $actor->id,
                'content_snapshot' => $snapshot,
                'content_hash' => hash('sha256', $snapshot),
            ])->save();
            $this->activity->log('warning.acknowledged', $actor, $locked, [
                'warning_id' => $locked->id, 'employee_id' => $locked->employee_id,
            ]);
        });

        return $warning->refresh()->load('acknowledgments');
    }

    public function close(EmployeeWarning $warning, User $actor): EmployeeWarning
    {
        throw_unless($this->authorization->allows($actor, HrPermission::WarningManage)
            && $this->authorization->canViewWarning($actor, $warning), AuthorizationException::class);

        return DB::transaction(function () use ($warning, $actor): EmployeeWarning {
            $locked = EmployeeWarning::query()->lockForUpdate()->findOrFail($warning->id);
            if ($locked->status === 'closed') {
                return $locked;
            }
            $locked->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $actor->id])->save();
            $this->activity->log('warning.closed', $actor, $locked, ['warning_id' => $locked->id, 'employee_id' => $locked->employee_id]);

            return $locked;
        });
    }
}
