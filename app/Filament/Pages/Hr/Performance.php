<?php

namespace App\Filament\Pages\Hr;

use App\Enums\PerformancePermission;
use App\Enums\TaskPermission;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\PerformanceAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Performance\PerformanceMatrixService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Url;

class Performance extends Page
{
    protected string $view = 'filament.pages.hr.performance';

    protected static ?string $slug = 'hr/performance';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Performance';

    #[Url]
    public string $period = 'this_month';

    #[Url]
    public string $fromDate = '';

    #[Url]
    public string $toDate = '';

    #[Url(as: 'employee')]
    public ?int $employeeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && collect(PerformancePermission::cases())
            ->contains(fn (PerformancePermission $permission): bool => app(PerformanceAuthorization::class)->allows($user, $permission));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $user = $this->user();
        $canOverview = $this->canOverview($user);
        if (! $canOverview) {
            if ($this->employeeId !== null && $this->employeeId !== $user->employee?->id) {
                abort(403);
            }
            $this->employeeId = $user->employee?->id;
        } elseif ($this->employeeId !== null) {
            $employee = Employee::query()->findOrFail($this->employeeId);
            abort_unless(app(PerformanceAuthorization::class)->canViewEmployee($user, $employee), 403);
        }
    }

    public function selectEmployee(int $employeeId): void
    {
        $employee = Employee::query()->findOrFail($employeeId);
        abort_unless(app(PerformanceAuthorization::class)->canViewEmployee($this->user(), $employee), 403);
        $this->employeeId = $employee->id;
    }

    public function showOverview(): void
    {
        abort_unless($this->canOverview($this->user()), 403);
        $this->employeeId = null;
    }

    public function getViewData(): array
    {
        $user = $this->user();
        $service = app(PerformanceMatrixService::class);
        [$from, $to] = $service->range($this->period, $this->fromDate, $this->toDate);
        $canOverview = $this->canOverview($user);
        $detail = null;
        $overview = collect();
        if ($this->employeeId !== null) {
            $employee = Employee::query()->with('team:id,name')->findOrFail($this->employeeId);
            try {
                $detail = $service->employee($user, $employee, $from, $to);
            } catch (AuthorizationException) {
                abort(403);
            }
        } elseif ($canOverview) {
            $overview = $service->overview($user, $from, $to);
        }

        return [
            'overview' => $overview,
            'detail' => $detail,
            'canOverview' => $canOverview,
            'rangeLabel' => $from->format('d M Y').' – '.$to->format('d M Y'),
            'taskUrl' => app(TaskAuthorization::class)->allows($user, TaskPermission::View) ? TaskResource::getUrl() : null,
        ];
    }

    private function canOverview(User $user): bool
    {
        $authorization = app(PerformanceAuthorization::class);

        return $authorization->allows($user, PerformancePermission::ViewAll)
            || $authorization->allows($user, PerformancePermission::ViewTeam);
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
