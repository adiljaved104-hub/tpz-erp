<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\TaskPermission;
use App\Models\Task;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskQueryService;
use App\Services\Tasks\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(TaskAuthorization::class)->authorize($request->user(), TaskPermission::View);

        return $this->page($request, app(TaskQueryService::class)->visible($request->user())->orderByDesc('id'), ['title', 'description'], fn ($task) => $this->present($request, $task));
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
            'meta' => $task->due_at?->format('Y-m-d H:i'), 'status' => $pending ? 'awaiting_confirmation' : $status];
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

        return [...$data, 'fields' => ['description' => $task->description, 'due_at' => $task->due_at?->toIso8601String(), 'follow_up_at' => $assignment?->follow_up_at ?? $task->follow_up_at,
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
        if (preg_match('/^(confirm|return)-(\\d+)$/', $action, $match)) {
            abort_unless($auth->canSupervise($user, $task), 403);
            $submission = $task->completionSubmissions()->findOrFail((int) $match[2]);
            $result = $match[1] === 'confirm' ? $service->confirmCompletion($submission, $user) : $service->returnToEmployee($submission, $data['note'] ?? '', $user);
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

        return $this->show($request, $result);
    }
}
