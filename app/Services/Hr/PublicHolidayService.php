<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicHolidayService
{
    public function __construct(private readonly HrAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data */
    public function save(array $data, User $actor, ?PublicHoliday $holiday = null): PublicHoliday
    {
        $this->authorize($actor);
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'holiday_date' => ['required', 'date', Rule::unique('public_holidays', 'holiday_date')->ignore($holiday?->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor, $holiday): PublicHoliday {
            $locked = $holiday === null ? null : PublicHoliday::query()->lockForUpdate()->findOrFail($holiday->id);
            if ($locked !== null && DB::table('leave_request_days')->where('public_holiday_id', $locked->id)->exists()
                && ($locked->holiday_date->toDateString() !== $validated['holiday_date'] || $locked->name !== trim($validated['name']))) {
                throw ValidationException::withMessages(['holiday_date' => 'A Holiday already referenced by Leave history cannot be renamed or moved.']);
            }
            $record = $locked ?? new PublicHoliday(['created_by_user_id' => $actor->id, 'status' => true]);
            $record->fill(['name' => trim($validated['name']), 'holiday_date' => $validated['holiday_date'], 'notes' => $validated['notes'] ?? null])->save();
            $this->activity->log($locked === null ? 'public_holiday.created' : 'public_holiday.updated', $actor, $record, ['holiday_date' => $record->holiday_date->toDateString(), 'changed_fields' => array_keys($validated)]);

            return $record;
        });
    }

    public function setStatus(PublicHoliday $holiday, bool $active, User $actor): PublicHoliday
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($holiday, $active, $actor): PublicHoliday {
            $locked = PublicHoliday::query()->lockForUpdate()->findOrFail($holiday->id);
            $locked->forceFill(['status' => $active])->save();
            $this->activity->log('public_holiday.status_changed', $actor, $locked, ['status' => $active ? 'active' : 'inactive']);

            return $locked;
        });
    }

    private function authorize(User $actor): void
    {
        throw_unless($this->authorization->allows($actor, HrPermission::WorkScheduleManage), AuthorizationException::class);
    }
}
