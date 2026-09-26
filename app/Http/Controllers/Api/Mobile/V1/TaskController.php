<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Models\Task;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskAssigneeService;
use App\Services\Tasks\TaskQueryService;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(TaskAuthorization::class)->authorize($request->user(), TaskPermission::View);

        $filter = $request->validate(['filter' => 'nullable|in:open,overdue,due_soon'])['filter'] ?? null;
        $query = app(TaskQueryService::class)->visible($request->user())->orderByDesc('id');
        if ($filter === 'open') {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        } elseif ($filter === 'overdue') {
            $query->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', now());
        } elseif ($filter === 'due_soon') {
            $query->whereNotIn('status', ['completed', 'cancelled'])->whereBetween('due_at', [now(), now()->addHours(24)]);
        }

        $response = $this->page(
            $request,
            $query,
            ['title', 'description'],
            fn ($task) => $this->present($request, $task),
        );

        $response->setData([
            ...$response->getData(true),
            'can_create' => app(TaskAuthorization::class)
                ->allows($request->user(), TaskPermission::Create),
        ]);

        return $response;
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $auth = app(TaskAuthorization::class);

        $auth->authorize($user, TaskPermission::Create);

        $assignees = app(TaskAssigneeService::class);

        return response()->json([
            'data' => [
                'can_create' => true,
                'can_assign' => $auth->allows($user, TaskPermission::Assign),

                'employees' => collect($assignees->options(null, $user))
                    ->map(fn (string $name, int|string $id): array => [
                        'id' => (int) $id,
                        'name' => $name,
                    ])
                    ->values()
                    ->all(),

                'teams' => collect($assignees->teamOptions($user))
                    ->map(fn (string $name, int|string $id): array => [
                        'id' => (int) $id,
                        'name' => $name,
                    ])
                    ->values()
                    ->all(),

                'priorities' => array_map(
                    fn (TaskPriority $priority): array => [
                        'value' => $priority->value,
                        'label' => $priority->getLabel(),
                    ],
                    TaskPriority::cases(),
                ),

                'assignment_modes' => array_map(
                    fn (TaskAssignmentMode $mode): array => [
                        'value' => $mode->value,
                        'label' => $mode->getLabel(),
                    ],
                    TaskAssignmentMode::cases(),
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        app(TaskAuthorization::class)
            ->authorize($user, TaskPermission::Create);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'priority' => 'required|in:low,normal,high,critical',

            'assignment_mode' =>
                'nullable|in:single_employee,multiple_employees,entire_team,team_queue',

            'assigned_employee_id' => 'nullable|integer',
            'assigned_employee_ids' => 'nullable|array|max:100',
            'assigned_employee_ids.*' => 'integer|distinct',
            'assigned_team_id' => 'nullable|integer',

            'due_at' => 'nullable|date',
            'follow_up_at' => 'nullable|date',

            'idempotency_key' => 'required|uuid',
        ]);

        if ($existing = Task::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->first()) {

            abort_unless(
                $existing->created_by_user_id === $user->id,
                403,
            );

            return $this->show($request, $existing);
        }

        $task = app(TaskService::class)->create(
            new CreateTaskData(
                title: $data['title'],
                description: $data['description'] ?? null,
                priority: TaskPriority::from($data['priority']),
                assignedEmployeeId: $data['assigned_employee_id'] ?? null,
                assignedTeamId: $data['assigned_team_id'] ?? null,
                dueAt: $data['due_at'] ?? null,
                followUpAt: $data['follow_up_at'] ?? null,
                linkedType: null,
                linkedRecordId: null,
                idempotencyKey: $data['idempotency_key'],
                assignedEmployeeIds: $data['assigned_employee_ids'] ?? [],
                assignmentMode: isset($data['assignment_mode'])
                    ? TaskAssignmentMode::from($data['assignment_mode'])
                    : null,
            ),
            $user,
        );

        return $this->show($request, $task);
    }
    public function show(Request $request, Task $task): JsonResponse
    {
        app(TaskAuthorization::class)->authorize($request->user(), TaskPermission::View, $task);

        return response()->json(['data' => $this->present($request, $task, true)]);
    }

    private function present(Request $request, Task $task, bool $detail = false): array
    {
        $auth = app(TaskAuthorization::class);
        $user = $request->user();
        $assignment = $auth->assignmentFor($user, $task);
        $status = $assignment?->status->value ?? $task->status->value;
        $pending = $task->completionSubmissions()->where('status', 'pending')
            ->when(! $auth->canSupervise($user, $task), fn ($q) => $q->where('submitted_by_user_id', $user->id))->exists();
        $data = ['id' => $task->id, 'title' => $task->title, 'subtitle' => 'Priority: '.$task->priority->value,
            'meta' => $task->dueLabel(), 'status' => $pending ? 'awaiting_confirmation' : $status];
        if (! $detail) {
            return $data;
        }
        $actions = [];
        $note = [$this->field('note', 'Progress / remarks', 'multiline')];
        if ($auth->isAssignee($user, $task) && $auth->allows($user, TaskPermission::ChangeStatus, $task)) {
            $actions[] = $this->action('comment', 'Add progress update', [$this->field('note', 'Progress update', 'multiline', true)]);
            if (! $pending) {
                if (in_array($status, ['pending', 'assigned'], true)) {
                    $actions[] = $this->action('start', 'Start task');
                }
                if ($status === 'in_progress') {
                    $actions[] = $this->action('wait', 'Waiting / blocked', [$this->field('note', 'Reason', 'multiline', true), $this->field('follow_up_at', 'Follow-up date (YYYY-MM-DD)', 'date')]);
                }
                if ($status === 'waiting') {
                    $actions[] = $this->action('resume', 'Resume task');
                }
            }
        }
        if (! $pending && $auth->isAssignee($user, $task) && $auth->allows($user, TaskPermission::Complete, $task) && in_array($status, ['in_progress', 'waiting'], true)) {
            $actions[] = $this->action('complete', 'Submit completion', $note);
        }
        if ($auth->canSupervise($user, $task)) {
            foreach ($task->completionSubmissions()->where('status', 'pending')->get() as $submission) {
                $actions[] = $this->action('confirm-'.$submission->id, 'Confirm completion #'.$submission->id);
                $actions[] = $this->action('return-'.$submission->id, 'Return completion #'.$submission->id, [$this->field('note', 'Reason', 'multiline', true)]);
            }
        }

        if (! $task->status->isTerminal() && $auth->allows($user, TaskPermission::Cancel, $task)) {
            $actions[] = $this->action('cancel', 'Cancel task', [$this->field('note', 'Cancellation reason', 'multiline', true)]);
        }
        if ($task->status === TaskStatus::Completed && $auth->canReopen($user, $task)) {
            $actions[] = $this->action('reopen', 'Reopen task', [$this->field('note', 'Reopen reason', 'multiline', true)]);
        }

        return [...$data, 'fields' => ['reference' => $task->reference, 'description' => $task->description, 'priority' => $task->priority->value,
            'due_at' => $task->due_at?->toIso8601String(), 'due_context' => $task->dueLabel(), 'overdue' => $task->isOverdue(),
            'follow_up_at' => $assignment?->follow_up_at ?? $task->follow_up_at,
            'assigned_to' => $task->assignmentLabel()],
            'history' => $task->events()->limit(30)->get()->map(fn ($e) => ['id' => $e->id, 'title' => $e->event_type->value, 'meta' => $e->note, 'created_at' => $e->occurred_at]),
            'actions' => $actions];
    }

    public function act(Request $request, Task $task, string $action): JsonResponse
    {
        $auth = app(TaskAuthorization::class);
        $user = $request->user();
        $auth->authorize($user, TaskPermission::View, $task);
        $data = $request->validate(['note' => 'nullable|string|max:5000', 'follow_up_at' => 'nullable|date']);
        $service = app(TaskService::class);
        try {
            if (preg_match('/^(confirm|return)-(\\d+)$/', $action, $match)) {
                abort_unless($auth->canSupervise($user, $task), 403);
                $submission = $task->completionSubmissions()->findOrFail((int) $match[2]);
                $result = $match[1] === 'confirm' ? $service->confirmCompletion($submission, $user) : $service->returnToEmployee($submission, $data['note'] ?? '', $user);
            } elseif ($action === 'cancel') {
                $result = $service->cancel($task, $data['note'] ?? '', $user);
            } elseif ($action === 'reopen') {
                $result = $service->reopen($task, $data['note'] ?? '', $user);
            } else {
                $auth->authorize($user, $action === 'complete' ? TaskPermission::Complete : TaskPermission::ChangeStatus, $task);
                $result = match ($action) {
                    'start' => $service->start($task, $user),
                    'wait' => $service->wait($task, $data['note'] ?? '', $data['follow_up_at'] ?? null, $user),
                    'resume' => $service->resume($task, $user),
                    'comment' => $service->comment($task, $data['note'] ?? '', $user),
                    'complete' => $service->submitForCompletion($task, $data['note'] ?? null, $user),
                    default => abort(404),
                };
            }
        } catch (TaskException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->show($request, $result);
    }
}
