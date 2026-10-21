<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\JoiningDateCorrectionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class VerifyEmployeeJoiningDate extends Page
{
    protected string $view = 'filament.pages.hr.verify-joining-date';

    protected static ?string $slug = 'hr/employees/{employee}/joining-date';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Verify / Correct Joining Date';

    public Employee $employee;

    public string $joiningDate = '';

    public string $reason = '';

    public bool $confirmed = false;

    public ?array $preview = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(HrAuthorization::class)->allows($user, HrPermission::LeaveJoiningDateCorrect);
    }

    public function mount(Employee $employee): void
    {
        abort_unless(static::canAccess(), 403);
        $this->employee = $employee;
        $this->joiningDate = $employee->joining_date?->toDateString() ?? '';
        $this->refreshPreview();
    }

    public function updatedJoiningDate(): void
    {
        $this->refreshPreview();
    }

    public function refreshPreview(): void
    {
        $this->preview = null;
        if ($this->joiningDate === '') {
            return;
        }
        try {
            $this->preview = app(JoiningDateCorrectionService::class)->preview($this->employee, $this->joiningDate, $this->user());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            $this->addError('joiningDate', collect($exception->errors())->flatten()->first());
        }
    }

    public function save(): void
    {
        try {
            $this->employee = app(JoiningDateCorrectionService::class)->verifyOrCorrect($this->employee, [
                'joining_date' => $this->joiningDate, 'reason' => $this->reason, 'confirmed' => $this->confirmed,
            ], $this->user());
            Notification::make()->success()->title('Joining date verified and audit saved')->send();
            // Keep HR/Admin on the review page so the verified balance and next
            // anniversary remain visible immediately after saving.
            $this->preview = app(JoiningDateCorrectionService::class)->preview($this->employee, $this->joiningDate, $this->user());
            $this->confirmed = false;
            $this->reason = '';
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(match ($field) {
                    'joining_date' => 'joiningDate', 'confirmed' => 'confirmed', default => 'reason'
                }, $messages[0]);
            }
        }
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
