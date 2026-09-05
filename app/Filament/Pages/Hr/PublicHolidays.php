<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\PublicHolidayService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class PublicHolidays extends Page
{
    protected string $view = 'filament.pages.hr.public-holidays';

    protected static ?string $slug = 'hr/public-holidays';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDateRange;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Public Holidays';

    public ?int $holidayId = null;

    public string $name = '';

    public string $holidayDate = '';

    public string $notes = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(HrAuthorization::class)->allows($user, HrPermission::WorkScheduleManage);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function edit(int $id): void
    {
        $holiday = PublicHoliday::query()->findOrFail($id);
        $this->holidayId = $holiday->id;
        $this->name = $holiday->name;
        $this->holidayDate = $holiday->holiday_date->toDateString();
        $this->notes = (string) $holiday->notes;
    }

    public function save(): void
    {
        try {
            app(PublicHolidayService::class)->save([
                'name' => $this->name, 'holiday_date' => $this->holidayDate, 'notes' => $this->notes ?: null,
            ], auth()->user(), $this->holidayId ? PublicHoliday::query()->findOrFail($this->holidayId) : null);
            $this->reset('holidayId', 'name', 'holidayDate', 'notes');
            $this->resetValidation();
            Notification::make()->success()->title('Public Holiday saved')->send();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str($field)->camel()->toString(), $messages[0]);
            }
        }
    }

    public function toggleStatus(int $id): void
    {
        $holiday = PublicHoliday::query()->findOrFail($id);
        app(PublicHolidayService::class)->setStatus($holiday, ! $holiday->status, auth()->user());
        Notification::make()->success()->title('Holiday status updated')->send();
    }

    public function getViewData(): array
    {
        return ['holidays' => PublicHoliday::query()->latest('holiday_date')->get()];
    }
}
