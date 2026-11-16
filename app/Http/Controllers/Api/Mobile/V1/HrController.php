<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Team;
use App\Models\WarningCategory;
use App\Services\Authorization\EmployeeDirectoryScopeService;
use App\Services\Authorization\HrAuthorization;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Hr\HrScopeService;
use App\Services\Hr\LeaveService;
use App\Services\Mobile\MobileHrSectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrController extends MobileController
{
    private function sections(Request $request): array
    {
        return app(MobileHrSectionService::class)->sections($request->user());
    }

    public function home(Request $request): JsonResponse
    {
        $sections = $this->sections($request);

        return response()->json(['data' => ['sections' => $sections,
            'can_request_leave' => collect($sections)->contains('key', 'leave-requests')
                && app(HrAuthorization::class)->allows($request->user(), HrPermission::LeaveRequest),
            'can_publish_notice' => app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::NoticePublish),
            'can_publish_announcement' => app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::AnnouncementPublish),
            'can_publish_all' => in_array($request->user()->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true),
            'can_issue_warning' => app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::WarningIssue)]]);
    }

    public function options(Request $request): JsonResponse
    {
        $auth = app(HrRecordAuthorization::class);
        $user = $request->user();
        $notice = $auth->allows($user, HrPermission::NoticePublish);
        $announcement = $auth->allows($user, HrPermission::AnnouncementPublish);
        $warning = $auth->allows($user, HrPermission::WarningIssue);
        abort_unless($notice || $announcement || $warning, 403);
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
            'teams' => ($notice || $announcement) ? $teams->get(['id', 'name']) : [],
            'employees' => ($notice || $announcement || $warning) ? $employees->get(['id', 'name', 'employee_id']) : [],
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
                    ->tap(fn ($query) => app(EmployeeDirectoryScopeService::class)->apply($query, $user))
                : Employee::query()->select(['id', 'employee_id', 'name', 'designation', 'team_id', 'role', 'status'])->whereKey($user->employee?->id),
            'teams' => $user->can('viewAny', Team::class) ? Team::query()->select(['id', 'name', 'description', 'status'])
                ->when($user->employee?->role === EmployeeRole::Manager,
                    fn ($q) => $user->employee?->team_id === null ? $q->whereRaw('1 = 0') : $q->whereKey($user->employee->team_id)) : abort(403),
            'notices' => $auth->scopeReadableCommunications(HrNotice::query()->select(['id', 'reference', 'title', 'content',
                'communication_type', 'priority', 'published_at', 'expires_at', 'status', 'acknowledgment_required', 'team_id']), $user),
            'warnings' => $auth->scopeWarnings(EmployeeWarning::query()->select(['id', 'reference', 'employee_id',
                'title', 'description', 'warning_level', 'issued_date', 'status', 'acknowledgment_required']), $user),
            'leave-requests' => app(HrScopeService::class)->leaveQuery($user)
                ->with(['employee:id,name,employee_id,team_id', 'type:id,name'])
                ->select(['id', 'reference', 'employee_id', 'leave_type_id', 'from_date', 'to_date',
                    'requested_working_days', 'status', 'reason', 'submitted_at', 'decided_at', 'decision_reason']),
            'attendance' => app(HrScopeService::class)->attendanceQuery($user)
                ->with(['employee:id,name,employee_id,team_id', 'schedule:id,name,timezone', 'corrections.actor:id,name'])
                ->select(['id', 'employee_id', 'attendance_date', 'work_schedule_id', 'expected_start_at',
                    'expected_end_at', 'first_check_in_at', 'last_check_out_at', 'worked_minutes', 'status',
                    'late_minutes', 'early_departure_minutes', 'source', 'is_overridden', 'calculated_at']),
            default => abort(404),
        };
    }

    public function index(Request $request, string $section): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', $section), 403);
        $query = $this->query($request, $section)->orderByDesc('id');
        $search = in_array($section, ['notices', 'warnings'], true) ? ['reference', 'title'] : ['name'];
        if ($section === 'leave-requests') {
            $search = ['reference', 'reason'];
        }
        if ($section === 'attendance') {
            $search = ['attendance_date', 'status'];
        }
        if ($section === 'teams') {
            $search = ['name'];
        }

        return $this->page($request, $query, $search, fn ($row) => $this->present($request, $section, $row));
    }

    public function show(Request $request, string $section, int $record): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', $section), 403);
        $row = $this->query($request, $section)->findOrFail($record);
        if ($section === 'notices') {
            app(HrNoticeService::class)->markRead($row, $request->user());
        }
        if ($section === 'employees') {
            abort_unless($request->user()->can('view', $row), 403);
        }

        return response()->json(['data' => $this->present($request, $section, $row, true)]);
    }

    private function present(Request $request, string $section, $row, bool $detail = false): array
    {
        $recipientAcknowledgment = $section === 'notices' && $request->user()->employee !== null
            ? HrAcknowledgment::query()->where('hr_notice_id', $row->id)->where('employee_id', $request->user()->employee->id)->first()
            : null;
        $communicationStatus = $section === 'notices' && $row->status === 'active' && $row->expires_at?->isPast()
            ? 'expired'
            : $row->status;
        $data = match ($section) {
            'employees' => ['id' => $row->id, 'title' => $row->name, 'subtitle' => $row->designation,
                'meta' => $row->employee_id, 'status' => $row->status ? 'active' : 'inactive'],
            'teams' => ['id' => $row->id, 'title' => $row->name, 'subtitle' => $row->description, 'status' => $row->status ? 'active' : 'inactive'],
            'notices' => ['id' => $row->id, 'title' => $row->title, 'subtitle' => $row->reference,
                'meta' => $row->published_at?->format('Y-m-d'), 'status' => $communicationStatus,
                'communication_type' => $row->communication_type,
                'read_state' => $recipientAcknowledgment?->read_at !== null ? 'read' : 'unread',
                'acknowledgment_state' => ! $row->acknowledgment_required ? 'not_required'
                    : ($recipientAcknowledgment?->acknowledged_at !== null ? 'acknowledged' : 'pending')],
            'warnings' => ['id' => $row->id, 'title' => $row->title, 'subtitle' => $row->reference,
                'meta' => $row->issued_date?->format('Y-m-d'), 'status' => $row->status],
            'leave-requests' => ['id' => $row->id, 'title' => $row->reference,
                'subtitle' => collect([$row->employee?->name, $row->type?->name])->filter()->implode(' · '),
                'meta' => $row->from_date?->format('Y-m-d').' → '.$row->to_date?->format('Y-m-d'),
                'status' => $row->status],
            'attendance' => ['id' => $row->id, 'title' => $row->employee?->name ?? 'Attendance',
                'subtitle' => $row->employee?->employee_id,
                'meta' => $row->attendance_date?->format('Y-m-d'), 'status' => $row->status->value],
        };
        if (! $detail) {
            return $data;
        }
        $fields = match ($section) {
            'employees' => ['employee_reference' => $row->employee_id, 'designation' => $row->designation,
                'team' => $row->team?->name, 'role' => $row->role?->value],
            'teams' => ['description' => $row->description],
            'notices' => ['content' => $row->content, 'communication_type' => $row->communication_type,
                'priority' => $row->priority, 'published_at' => $row->published_at?->format('Y-m-d H:i'),
                'expires_at' => $row->expires_at?->format('Y-m-d H:i'),
                'acknowledgment_required' => $row->acknowledgment_required,
                'read_at' => $recipientAcknowledgment?->read_at?->format('Y-m-d H:i'),
                'acknowledged_at' => $recipientAcknowledgment?->acknowledged_at?->format('Y-m-d H:i')],
            'warnings' => ['description' => $row->description, 'level' => $row->warning_level->value,
                'issued_date' => $row->issued_date?->format('Y-m-d')],
            'leave-requests' => ['employee' => $row->employee?->name, 'employee_reference' => $row->employee?->employee_id,
                'leave_type' => $row->type?->name, 'from_date' => $row->from_date?->format('Y-m-d'),
                'to_date' => $row->to_date?->format('Y-m-d'), 'working_days' => $row->requested_working_days,
                'reason' => $row->reason, 'submitted_at' => $row->submitted_at?->toIso8601String(),
                'decided_at' => $row->decided_at?->toIso8601String(), 'decision_reason' => $row->decision_reason],
            'attendance' => ['employee' => $row->employee?->name, 'employee_reference' => $row->employee?->employee_id,
                'attendance_date' => $row->attendance_date?->format('Y-m-d'), 'schedule' => $row->schedule?->name,
                'timezone' => $row->schedule?->timezone ?: 'Asia/Karachi',
                'expected_start' => $row->expected_start_at?->toIso8601String(),
                'expected_end' => $row->expected_end_at?->toIso8601String(),
                'first_check_in' => $row->first_check_in_at?->toIso8601String(),
                'last_check_out' => $row->last_check_out_at?->toIso8601String(),
                'worked_minutes' => $row->worked_minutes, 'late_minutes' => $row->late_minutes,
                'early_departure_minutes' => $row->early_departure_minutes, 'source' => $row->source,
                'is_overridden' => $row->is_overridden, 'calculated_at' => $row->calculated_at?->toIso8601String()],
        };
        $actions = [];
        if ($section === 'attendance' && app(HrScopeService::class)->canCorrect($request->user(), $row->employee)) {
            $timezone = (string) ($row->schedule?->timezone ?: 'Asia/Karachi');
            $statusOptions = collect(AttendanceStatus::cases())->map(fn (AttendanceStatus $status): array => [
                'value' => $status->value,
                'label' => str($status->value)->replace('_', ' ')->title()->toString(),
            ])->all();
            $actions[] = $this->action('correct', 'Correct attendance', [
                $this->field('status', 'Status', 'select', true, $row->status->value, $statusOptions),
                $this->field('first_check_in_at', 'First check-in', 'datetime', false,
                    $row->first_check_in_at?->timezone($timezone)->format('Y-m-d\TH:i:sP')),
                $this->field('last_check_out_at', 'Last check-out', 'datetime', false,
                    $row->last_check_out_at?->timezone($timezone)->format('Y-m-d\TH:i:sP')),
                $this->field('late_minutes', 'Late minutes', 'number', true, (int) $row->late_minutes),
                $this->field('early_departure_minutes', 'Early departure minutes', 'number', true, (int) $row->early_departure_minutes),
                $this->field('reason', 'Correction reason', 'multiline', true),
            ], confirmationMessage: 'This correction is audited and does not alter raw biometric evidence.');
        }
        if ($section === 'leave-requests' && $row->status === 'pending') {
            $scope = app(HrScopeService::class);
            if ($scope->canApprove($request->user(), $row)) {
                $actions[] = $this->action('approve', 'Approve leave request', confirmationMessage: 'Approve this pending leave request?');
                $actions[] = $this->action('reject', 'Reject leave request', [
                    $this->field('reason', 'Rejection reason', 'multiline', true),
                ]);
            }
            if ($request->user()->employee?->id === $row->employee_id
                && app(HrAuthorization::class)->allows($request->user(), HrPermission::LeaveRequest)) {
                $actions[] = $this->action('cancel', 'Cancel leave request');
            }
        }
        if (in_array($section, ['notices', 'warnings'], true) && $row->acknowledgment_required && $request->user()->employee) {
            $column = $section === 'notices' ? 'hr_notice_id' : 'employee_warning_id';
            if (HrAcknowledgment::query()->where($column, $row->id)->where('employee_id', $request->user()->employee->id)->whereNull('acknowledged_at')->exists()) {
                $actions[] = $this->action('acknowledge', 'Acknowledge');
            }
        }

        $items = $section === 'teams' ? Employee::query()->where('team_id', $row->id)
            ->select(['id', 'employee_id', 'name', 'designation', 'role', 'status'])->orderBy('name')->get()
            ->map(fn (Employee $employee): array => ['id' => $employee->id, 'employee_reference' => $employee->employee_id,
                'name' => $employee->name, 'designation' => $employee->designation, 'role' => $employee->role?->value,
                'status' => $employee->status ? 'active' : 'inactive'])->all() : [];

        return [...$data, 'fields' => $fields, 'items' => $items, 'actions' => $actions];
    }

    public function leaveOptions(Request $request): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', 'leave-requests')
            && app(HrAuthorization::class)->allows($request->user(), HrPermission::LeaveRequest), 403);

        return response()->json(['data' => [
            'leave_types' => LeaveType::query()->where('status', true)
                ->whereIn('code', ['annual', 'unpaid'])
                ->orderBy('name')->get(['id', 'name', 'code']),
        ]]);
    }

    public function submitLeave(Request $request): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', 'leave-requests'), 403);
        $idempotency = $request->validate(['idempotency_key' => ['nullable', 'uuid']]);
        $leave = app(LeaveService::class)->submit(
            $request->all(),
            $request->user(),
            $idempotency['idempotency_key'] ?? null,
        );

        return $this->show($request, 'leave-requests', $leave->id);
    }

    public function approveLeave(Request $request, int $record): JsonResponse
    {
        $leave = $this->leaveRecord($request, $record);
        app(LeaveService::class)->approve($leave, $request->user());

        return $this->show($request, 'leave-requests', $record);
    }

    public function rejectLeave(Request $request, int $record): JsonResponse
    {
        $leave = $this->leaveRecord($request, $record);
        app(LeaveService::class)->reject($leave, (string) $request->input('reason'), $request->user());

        return $this->show($request, 'leave-requests', $record);
    }

    public function cancelLeave(Request $request, int $record): JsonResponse
    {
        $leave = $this->leaveRecord($request, $record);
        app(LeaveService::class)->cancel($leave, $request->user());

        return $this->show($request, 'leave-requests', $record);
    }

    private function leaveRecord(Request $request, int $record): LeaveRequest
    {
        abort_unless(collect($this->sections($request))->contains('key', 'leave-requests'), 403);

        return $this->query($request, 'leave-requests')->findOrFail($record);
    }

    public function correctAttendance(Request $request, int $record): JsonResponse
    {
        abort_unless(collect($this->sections($request))->contains('key', 'attendance'), 403);
        $attendance = $this->query($request, 'attendance')->findOrFail($record);
        app(AttendanceCorrectionService::class)->correct(
            $attendance,
            $request->only(['status', 'first_check_in_at', 'last_check_out_at', 'late_minutes', 'early_departure_minutes']),
            (string) $request->input('reason'),
            $request->user(),
        );

        return $this->show($request, 'attendance', $record);
    }

    public function publishNotice(Request $request): JsonResponse
    {
        abort_unless(app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::NoticePublish), 403);
        $notice = app(HrNoticeService::class)->publish($request->all(), $request->user());

        return $this->show($request, 'notices', $notice->id);
    }

    public function publishAnnouncement(Request $request): JsonResponse
    {
        abort_unless(app(HrRecordAuthorization::class)->allows($request->user(), HrPermission::AnnouncementPublish), 403);
        $announcement = app(HrNoticeService::class)->publishAnnouncement($request->all(), $request->user());

        return $this->show($request, 'notices', $announcement->id);
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
