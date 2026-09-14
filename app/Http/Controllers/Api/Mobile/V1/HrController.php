<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\Team;
use App\Models\WarningCategory;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrController extends MobileController
{
    private function sections(Request $request): array
    {
        $user = $request->user();
        $auth = app(HrRecordAuthorization::class);

        return array_values(array_filter([
            $user->can('viewAny', Employee::class) || $user->can('view', $user->employee)
                ? ['key' => 'employees', 'title' => 'Employees'] : null,
            $user->can('viewAny', Team::class) ? ['key' => 'teams', 'title' => 'Teams'] : null,
            $auth->allows($user, HrPermission::NoticeView) || $auth->allows($user, HrPermission::NoticeManage)
                ? ['key' => 'notices', 'title' => 'HR Notices'] : null,
            $auth->allows($user, HrPermission::WarningViewOwn) || $auth->allows($user, HrPermission::WarningViewTeam)
                || $auth->allows($user, HrPermission::WarningViewAll)
                ? ['key' => 'warnings', 'title' => 'Employee Warnings'] : null,
        ]));
    }

    public function home(Request $request): JsonResponse
    {
        return response()->json(['data' => ['sections' => $this->sections($request),
            'can_publish_notice' => app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::NoticePublish),
            'can_publish_all' => in_array($request->user()->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true),
            'can_issue_warning' => app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::WarningIssue)]]);
    }

    public function options(Request $request): JsonResponse
    {
        $auth = app(HrRecordAuthorization::class);
        $user = $request->user();
        $notice = $auth->allows($user, HrPermission::NoticePublish);
        $warning = $auth->allows($user, HrPermission::WarningIssue);
        abort_unless($notice || $warning, 403);
        $teams = Team::query()->where('status', true);
        $employees = Employee::query()->where('status', true);
        if (! in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            if ($user->employee?->team_id === null) {
                $teams->whereRaw('1 = 0');
                $employees->whereKey($user->employee?->id);
            } else {
                $teams->whereKey($user->employee->team_id);
                $employees->where('team_id', $user->employee->team_id);
            }
        }

        return response()->json(['data' => [
            'teams' => $notice ? $teams->get(['id', 'name']) : [],
            'employees' => ($notice || $warning) ? $employees->get(['id', 'name', 'employee_id']) : [],
            'warning_categories' => $warning ? WarningCategory::query()->where('status', true)->get(['id', 'name']) : [],
        ]]);
    }

    private function query(Request $request, string $section)
    {
        $user = $request->user();
        $auth = app(HrRecordAuthorization::class);

        return match ($section) {
            'employees' => $user->can('viewAny', Employee::class)
                ? Employee::query()->select(['id', 'employee_id', 'name', 'designation', 'team_id', 'role', 'status'])
                    ->when($user->employee?->role === EmployeeRole::Manager,
                        fn ($q) => $user->employee->team_id === null ? $q->whereKey($user->employee->id) : $q->where('team_id', $user->employee->team_id))
                : Employee::query()->select(['id', 'employee_id', 'name', 'designation', 'team_id', 'role', 'status'])->whereKey($user->employee?->id),
            'teams' => $user->can('viewAny', Team::class) ? Team::query()->select(['id', 'name', 'description', 'status']) : abort(403),
            'notices' => $auth->scopeNotices(HrNotice::query()->select(['id', 'reference', 'title', 'content',
                'priority', 'published_at', 'status', 'acknowledgment_required', 'team_id']), $user),
            'warnings' => $auth->scopeWarnings(EmployeeWarning::query()->select(['id', 'reference', 'employee_id',
                'title', 'description', 'warning_level', 'issued_date', 'status', 'acknowledgment_required']), $user),
            default => abort(404),
        };
    }

    public function index(Request $request, string $section): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', $section), 403);
        $query = $this->query($request, $section)->orderByDesc('id');
        $search = in_array($section, ['notices', 'warnings'], true) ? ['reference', 'title'] : ['name'];
        if ($section === 'teams') {
            $search = ['name'];
        }

        return $this->page($request, $query, $search, fn ($row) => $this->present($request, $section, $row));
    }

    public function show(Request $request, string $section, int $record): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', $section), 403);
        $row = $this->query($request, $section)->findOrFail($record);
        if ($section === 'employees') {
            abort_unless($request->user()->can('view', $row), 403);
        }

        return response()->json(['data' => $this->present($request, $section, $row, true)]);
    }

    private function present(Request $request, string $section, $row, bool $detail = false): array
    {
        $data = match ($section) {
            'employees' => ['id' => $row->id, 'title' => $row->name, 'subtitle' => $row->designation,
                'meta' => $row->employee_id, 'status' => $row->status ? 'active' : 'inactive'],
            'teams' => ['id' => $row->id, 'title' => $row->name, 'subtitle' => $row->description, 'status' => $row->status ? 'active' : 'inactive'],
            'notices' => ['id' => $row->id, 'title' => $row->title, 'subtitle' => $row->reference,
                'meta' => $row->published_at?->format('Y-m-d'), 'status' => $row->status],
            'warnings' => ['id' => $row->id, 'title' => $row->title, 'subtitle' => $row->reference,
                'meta' => $row->issued_date?->format('Y-m-d'), 'status' => $row->status],
        };
        if (! $detail) {
            return $data;
        }
        $fields = match ($section) {
            'employees' => ['employee_reference' => $row->employee_id, 'designation' => $row->designation,
                'team' => $row->team?->name, 'role' => $row->role?->value],
            'teams' => ['description' => $row->description],
            'notices' => ['content' => $row->content, 'priority' => $row->priority,
                'published_at' => $row->published_at?->format('Y-m-d H:i')],
            'warnings' => ['description' => $row->description, 'level' => $row->warning_level->value,
                'issued_date' => $row->issued_date?->format('Y-m-d')],
        };
        $actions = [];
        if (in_array($section, ['notices', 'warnings'], true) && $row->acknowledgment_required && $request->user()->employee) {
            $column = $section === 'notices' ? 'hr_notice_id' : 'employee_warning_id';
            if (HrAcknowledgment::query()->where($column, $row->id)->where('employee_id', $request->user()->employee->id)->whereNull('acknowledged_at')->exists()) {
                $actions[] = $this->action('acknowledge', 'Acknowledge');
            }
        }

        return [...$data, 'fields' => $fields, 'actions' => $actions];
    }

    public function publishNotice(Request $request): JsonResponse
    {
        abort_unless(app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::NoticePublish), 403);
        $notice = app(HrNoticeService::class)->publish($request->all(), $request->user());

        return $this->show($request, 'notices', $notice->id);
    }

    public function issueWarning(Request $request): JsonResponse
    {
        abort_unless(app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::WarningIssue), 403);
        $warning = app(EmployeeWarningService::class)->issue($request->all(), $request->user());

        return $this->show($request, 'warnings', $warning->id);
    }

    public function acknowledge(Request $request, string $section, int $record): JsonResponse
    {
        if ($section === 'notices') {
            app(HrNoticeService::class)->acknowledge(HrNotice::query()->findOrFail($record), $request->user());
        } elseif ($section === 'warnings') {
            app(EmployeeWarningService::class)->acknowledge(EmployeeWarning::query()->findOrFail($record), $request->user());
        } else {
            abort(404);
        }

        return $this->show($request, $section, $record);
    }
}
