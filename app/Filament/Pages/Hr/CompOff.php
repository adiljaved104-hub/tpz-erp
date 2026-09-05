<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\CompensatoryOff;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\CompensatoryOffService;
use App\Services\Hr\HrScopeService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class CompOff extends Page
{
    protected string $view = 'filament.pages.hr.comp-off';

    protected static ?string $slug = 'hr/comp-off';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Comp Off';

    public ?int $employeeId = null;

    public string $earnedWorkDate = '';

    public string $offDate = '';

    public string $reason = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect([
            HrPermission::LeaveViewOwn, HrPermission::LeaveViewTeam, HrPermission::LeaveViewAll,
            HrPermission::LeaveApprove, HrPermission::WorkScheduleManage,
        ])->contains(fn (HrPermission $permission): bool => app(HrAuthorization::class)->allows($user, $permission));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function createFromSundayDuty(): void
    {
        try {
            app(CompensatoryOffService::class)->requestFromSundayDuty([
                'employee_id' => $this->employeeId, 'earned_work_date' => $this->earnedWorkDate,
                'off_date' => $this->offDate, 'reason' => $this->reason,
            ], $this->user());
            $this->reset('employeeId', 'earnedWorkDate', 'offDate', 'reason');
            $this->resetValidation();
            Notification::make()->success()->title('Comp Off submitted for approval')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(str($field)->camel()->toString(), $messages[0]);
            }
        }
    }

    public function approve(int $id): void
    {
        try {
            app(CompensatoryOffService::class)->approve(CompensatoryOff::query()->with('employee')->findOrFail($id), $this->user());
            Notification::make()->success()->title('Comp Off approved')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Cannot approve Comp Off')->body(collect($exception->errors())->flatten()->first())->send();
        }
    }

    public function canApprove(CompensatoryOff $record): bool
    {
        return app(HrScopeService::class)->canApproveEmployee($this->user(), $record->employee);
    }

    public function getViewData(): array
    {
        $user = $this->user();
        $employees = app(HrScopeService::class)->employeeQuery($user, false)->orderBy('name')->get(['id', 'name', 'employee_id', 'team_id']);
        $query = CompensatoryOff::query()->with('employee:id,name,employee_id,team_id')
            ->whereIn('employee_id', $employees->pluck('id'))->latest('off_date');

        return [
            'records' => $query->get(),
            'employees' => $employees,
            'canCreate' => app(HrAuthorization::class)->allows($user, HrPermission::WorkScheduleManage),
        ];
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
