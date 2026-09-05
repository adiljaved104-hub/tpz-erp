<?php

namespace App\Services\Tasks;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskAssignmentStatus;
use App\Enums\TaskCompletionSubmissionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskPermission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAssignmentEvent;
use App\Models\TaskCompletionSubmission;
use App\Models\TaskCompletionSubmissionEvent;
use App\Models\TaskEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Notifications\TaskNotificationDispatcher;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskService
{
    public function __construct(
        private readonly TaskAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly TaskAssigneeService $assignees,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    public function create(CreateTaskData $data, User $actor): Task
    {
        $this->authorization->authorize($actor, TaskPermission::Create);
        [$employeeIds, $teamQueueId, $mode] = $this->resolveAssignmentMode(
            $data->assignmentMode,
            $this->employeeIds($data->assignedEmployeeIds, $data->assignedEmployeeId),
            $data->assignedTeamId,
            $actor,
        );
        if ($employeeIds !== [] || $teamQueueId !== null) {
            $this->authorization->authorize($actor, TaskPermission::Assign);
        }
        $this->validateAssignment($employeeIds, $teamQueueId, $actor);
        if (($data->linkedType === null) !== ($data->linkedRecordId === null)) {
            throw ValidationException::withMessages(['linked_record_id' => 'A linked type and record must be supplied together.']);
        }
        $reference = $this->references->nextTaskReference();

        $task = DB::transaction(function () use ($data, $actor, $reference, $employeeIds, $teamQueueId, $mode): Task {
            if ($existing = Task::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
                return $existing;
            }
            $assigned = $employeeIds !== [] || $teamQueueId !== null;
            $task = Task::query()->create([
                'reference' => $reference,
                'title' => trim($data->title),
                'description' => filled($data->description) ? trim($data->description) : null,
                'status' => $assigned ? TaskStatus::Assigned : TaskStatus::Pending,
                'priority' => $data->priority,
                'assigned_employee_id' => null,
                'assigned_team_id' => $teamQueueId,
                'created_by_user_id' => $actor->id,
                'due_at' => $data->dueAt,
                'follow_up_at' => $data->followUpAt,
                'linked_type' => $data->linkedType,
                'linked_record_id' => $data->linkedRecordId,
                'idempotency_key' => $data->idempotencyKey,
            ]);
            $this->event($task, TaskEventType::Created, $actor, null, $task->status, null, null, ['title', 'priority', 'due_at', 'linked_type']);
            foreach ($employeeIds as $employeeId) {
                $this->createAssignment($task, $employeeId, $actor);
            }
            if ($assigned) {
                $this->event($task, TaskEventType::Assigned, $actor, TaskStatus::Pending, TaskStatus::Assigned, null, null, [
                    'assigned_employee_ids' => $employeeIds,
                    'assigned_team_id' => $teamQueueId,
                    'assignment_mode' => $mode->value,
                ]);
            }

            return $this->load($task->refresh());
        });

        foreach ($task->assignments as $assignment) {
            $this->notifications->assigned($assignment);
        }

        return $task;
    }

    public function assign(Task $task, ?int $employeeId, ?int $teamId, User $actor): Task
    {
        return $this->assignMany($task, $employeeId === null ? [] : [$employeeId], $teamId, $actor);
    }

    /** @param array<int, int|string> $employeeIds */
    public function assignByMode(Task $task, TaskAssignmentMode $mode, array $employeeIds, ?int $teamId, User $actor): Task
    {
        [$employeeIds, $teamQueueId] = $this->resolveAssignmentMode($mode, $this->employeeIds($employeeIds), $teamId, $actor);

        return $this->assignMany($task, $employeeIds, $teamQueueId, $actor);
    }

    /** @param array<int, int|string> $employeeIds */
    public function assignMany(Task $task, array $employeeIds, ?int $teamId, User $actor): Task
    {
        $this->authorization->authorize($actor, TaskPermission::Assign, $task);
        $employeeIds = $this->employeeIds($employeeIds);
        $this->validateAssignment($employeeIds, $teamId, $actor);

        $changedAssignmentIds = [];
        $task = DB::transaction(function () use ($task, $employeeIds, $teamId, $actor, &$changedAssignmentIds): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status->isTerminal()) {
                throw new TaskException('Completed or cancelled Tasks cannot be reassigned.');
            }
            $existing = $task->assignments()->lockForUpdate()->get()->keyBy('employee_id');
            $currentEmployeeIds = $existing->reject(fn (TaskAssignment $assignment): bool => in_array($assignment->status, [TaskAssignmentStatus::Cancelled, TaskAssignmentStatus::Removed], true))
                ->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $targetEmployeeIds = collect($employeeIds)->sort()->values()->all();
            if ($currentEmployeeIds === $targetEmployeeIds && $task->assigned_team_id === $teamId && $task->assigned_employee_id === null) {
                return $this->load($task);
            }
            $completedEmployeeIds = $existing->filter(fn (TaskAssignment $assignment): bool => $assignment->status === TaskAssignmentStatus::Completed)
                ->keys()->map(fn ($id): int => (int) $id)->all();
            if (array_diff($completedEmployeeIds, $employeeIds) !== []) {
                throw new TaskException('Confirmed Employee assignments cannot be removed from an active Task.');
            }
            foreach ($existing as $employeeId => $assignment) {
                if (! in_array((int) $employeeId, $employeeIds, true) && $assignment->status->isActive()) {
                    if ($assignment->completionSubmissions()->where('status', 'pending')->exists()) {
                        throw new TaskException('An Employee awaiting completion confirmation cannot be removed.');
                    }
                    $from = $assignment->status;
                    $assignment->forceFill(['status' => TaskAssignmentStatus::Removed, 'removed_at' => now(), 'follow_up_at' => null])->save();
                    $this->assignmentEvent($assignment, 'removed', $actor, $from, TaskAssignmentStatus::Removed);
                }
            }
            foreach ($employeeIds as $employeeId) {
                $assignment = $existing->get($employeeId);
                if ($assignment === null) {
                    $changedAssignmentIds[] = $this->createAssignment($task, $employeeId, $actor)->id;
                } elseif (in_array($assignment->status, [TaskAssignmentStatus::Cancelled, TaskAssignmentStatus::Removed], true)) {
                    $from = $assignment->status;
                    $employee = Employee::query()->with('team:id,name')->findOrFail($employeeId);
                    $assignment->forceFill([
                        'status' => TaskAssignmentStatus::Assigned,
                        'team_id_at_assignment' => $employee->team_id,
                        'team_name_at_assignment' => $employee->team?->name,
                        'completed_at' => null,
                        'cancelled_at' => null,
                        'removed_at' => null,
                    ])->save();
                    $this->assignmentEvent($assignment, 'reopened', $actor, $from, TaskAssignmentStatus::Assigned);
                    $changedAssignmentIds[] = $assignment->id;
                }
            }
            $old = ['assigned_employee_id' => $task->assigned_employee_id, 'assigned_team_id' => $task->assigned_team_id];
            $task->forceFill(['assigned_employee_id' => null, 'assigned_team_id' => $teamId])->save();
            $this->syncParent($task);
            $this->event($task, TaskEventType::Reassigned, $actor, null, $task->status, null, $old, [
                'assigned_employee_ids' => $employeeIds,
                'assigned_team_id' => $teamId,
            ]);

            return $this->load($task->refresh());
        });

        foreach ($task->assignments->whereIn('id', $changedAssignmentIds) as $assignment) {
            $this->notifications->assigned($assignment, reassigned: true);
        }

        return $task;
    }

    public function update(Task $task, array $data, User $actor): Task
    {
        $this->authorization->authorize($actor, TaskPermission::Update, $task);

        return DB::transaction(function () use ($task, $data, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status->isTerminal()) {
                throw new TaskException('Completed or cancelled Tasks are read-only.');
            }
            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $task->{$field} = filled($data[$field]) ? trim((string) $data[$field]) : null;
                }
            }
            if (array_key_exists('priority', $data)) {
                $priority = $data['priority'] instanceof TaskPriority ? $data['priority'] : TaskPriority::from($data['priority']);
                if ($priority !== $task->priority) {
                    $this->event($task, TaskEventType::PriorityChanged, $actor, null, null, null, ['priority' => $task->priority->value], ['priority' => $priority->value]);
                    $task->priority = $priority;
                }
            }
            if (array_key_exists('due_at', $data) && (string) $task->due_at !== (string) $data['due_at']) {
                $this->event($task, TaskEventType::DueDateChanged, $actor, null, null, null, ['due_at' => $task->due_at?->toDateTimeString()], ['due_at' => $data['due_at']]);
                $task->due_at = $data['due_at'];
            }
            $task->save();

            return $this->load($task->refresh());
        });
    }

    public function start(Task $task, User $actor): Task
    {
        return $this->workerTransition($task, $actor, [TaskAssignmentStatus::Assigned], TaskAssignmentStatus::InProgress, 'started');
    }

    public function wait(Task $task, string $reason, ?string $followUpAt, User $actor): Task
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A waiting reason is required.']);
        }

        return $this->workerTransition($task, $actor, [TaskAssignmentStatus::InProgress], TaskAssignmentStatus::Waiting, 'waiting', $reason, ['follow_up_at' => $followUpAt]);
    }

    public function resume(Task $task, User $actor): Task
    {
        return $this->workerTransition($task, $actor, [TaskAssignmentStatus::Waiting], TaskAssignmentStatus::InProgress, 'resumed', null, ['follow_up_at' => null]);
    }

    public function complete(Task $task, ?string $note, User $actor): Task
    {
        return $this->submitForCompletion($task, $note, $actor);
    }

    public function submitForCompletion(Task $task, ?string $note, User $actor): Task
    {
        $assignment = $this->workerAssignment($task, $actor);
        $legacy = $assignment === null && $this->authorization->isAssignee($actor, $task);
        if ((! $legacy && ($assignment === null || ! in_array($assignment->status, [TaskAssignmentStatus::InProgress, TaskAssignmentStatus::Waiting], true)))
            || ($legacy && ! in_array($task->status, [TaskStatus::InProgress, TaskStatus::Waiting], true))) {
            throw new AuthorizationException;
        }

        $createdSubmissionId = null;
        $task = DB::transaction(function () use ($task, $assignment, $note, $actor, &$createdSubmissionId): Task {
            $assignment = $assignment === null ? null : TaskAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $fingerprint = $assignment === null ? 'task:'.$task->id.':legacy' : 'task:'.$task->id.':assignment:'.$assignment->id;
            if (TaskCompletionSubmission::query()->where('active_fingerprint', $fingerprint)->exists()) {
                return $this->load(Task::query()->findOrFail($task->id));
            }
            $submission = TaskCompletionSubmission::query()->create([
                'task_id' => $task->id,
                'task_assignment_id' => $assignment?->id,
                'status' => TaskCompletionSubmissionStatus::Pending,
                'active_fingerprint' => $fingerprint,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
                'completion_note' => filled($note) ? trim((string) $note) : null,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $createdSubmissionId = $submission->id;
            $this->completionEvent($submission, 'submitted', $actor);

            return $this->load(Task::query()->findOrFail($task->id));
        });

        if ($createdSubmissionId !== null) {
            $this->notifications->awaitingConfirmation(TaskCompletionSubmission::query()->findOrFail($createdSubmissionId));
        }

        return $task;
    }

    public function confirmCompletion(TaskCompletionSubmission $submission, User $actor): Task
    {
        $task = $submission->task;
        if (! $this->authorization->canSupervise($actor, $task)) {
            throw new AuthorizationException;
        }

        $confirmed = false;
        $task = DB::transaction(function () use ($submission, $actor, &$confirmed): Task {
            $submission = TaskCompletionSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status !== TaskCompletionSubmissionStatus::Pending) {
                return $this->load($submission->task);
            }
            $assignment = $submission->task_assignment_id === null ? null : TaskAssignment::query()->lockForUpdate()->findOrFail($submission->task_assignment_id);
            $from = $assignment?->status;
            $assignment?->forceFill(['status' => TaskAssignmentStatus::Completed, 'completed_at' => now(), 'follow_up_at' => null])->save();
            $submission->forceFill([
                'status' => TaskCompletionSubmissionStatus::Confirmed,
                'active_fingerprint' => null,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ])->save();
            $confirmed = true;
            if ($assignment !== null) {
                $this->assignmentEvent($assignment, 'completed', $actor, $from, TaskAssignmentStatus::Completed);
            }
            $this->completionEvent($submission, 'confirmed', $actor);
            $task = Task::query()->lockForUpdate()->findOrFail($submission->task_id);
            $before = $task->status;
            if ($assignment === null) {
                $task->forceFill(['status' => TaskStatus::Completed, 'completed_at' => now(), 'follow_up_at' => null])->save();
            } else {
                $this->syncParent($task);
            }
            if ($task->status === TaskStatus::Completed) {
                $this->event($task, TaskEventType::Completed, $actor, $before, TaskStatus::Completed);
            }

            return $this->load($task->refresh());
        });

        if ($confirmed) {
            $this->notifications->completionConfirmed($submission->refresh());
        }

        return $task;
    }

    public function returnToEmployee(TaskCompletionSubmission $submission, string $reason, User $actor): Task
    {
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A return reason is required.']);
        }
        $task = $submission->task;
        if (! $this->authorization->canSupervise($actor, $task)) {
            throw new AuthorizationException;
        }

        $task = DB::transaction(function () use ($submission, $reason, $actor): Task {
            $submission = TaskCompletionSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($submission->status !== TaskCompletionSubmissionStatus::Pending) {
                throw new TaskException('This completion submission has already been decided.');
            }
            $assignment = $submission->task_assignment_id === null ? null : TaskAssignment::query()->lockForUpdate()->findOrFail($submission->task_assignment_id);
            $assignment?->forceFill(['status' => TaskAssignmentStatus::InProgress, 'follow_up_at' => null])->save();
            $submission->forceFill([
                'status' => TaskCompletionSubmissionStatus::Returned,
                'active_fingerprint' => null,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => trim($reason),
            ])->save();
            $this->completionEvent($submission, 'returned', $actor, trim($reason));
            $task = Task::query()->lockForUpdate()->findOrFail($submission->task_id);
            if ($assignment === null) {
                $from = $task->status;
                $task->forceFill(['status' => TaskStatus::InProgress, 'follow_up_at' => null])->save();
                $this->event($task, TaskEventType::StatusChanged, $actor, $from, TaskStatus::InProgress, trim($reason));
            } else {
                $this->syncParent($task);
            }

            return $this->load($task->refresh());
        });

        $this->notifications->returned($submission->refresh());

        return $task;
    }

    public function cancel(Task $task, string $reason, User $actor): Task
    {
        $this->authorization->authorize($actor, TaskPermission::Cancel, $task);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
        }

        return DB::transaction(function () use ($task, $reason, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status->isTerminal()) {
                throw new TaskException('This Task is already final.');
            }
            foreach ($task->assignments()->lockForUpdate()->whereIn('status', ['assigned', 'in_progress', 'waiting'])->get() as $assignment) {
                $from = $assignment->status;
                $assignment->forceFill(['status' => TaskAssignmentStatus::Cancelled, 'cancelled_at' => now(), 'follow_up_at' => null])->save();
                $this->assignmentEvent($assignment, 'cancelled', $actor, $from, TaskAssignmentStatus::Cancelled, $reason);
            }
            $from = $task->status;
            $task->forceFill(['status' => TaskStatus::Cancelled, 'cancelled_at' => now()])->save();
            $this->event($task, TaskEventType::Cancelled, $actor, $from, TaskStatus::Cancelled, trim($reason));

            return $this->load($task->refresh());
        });
    }

    public function reopen(Task $task, string $reason, User $actor): Task
    {
        if (! $this->authorization->canReopen($actor, $task)) {
            throw new AuthorizationException;
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A reopen reason is required.']);
        }

        return DB::transaction(function () use ($task, $reason, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($task->status !== TaskStatus::Completed) {
                throw new TaskException('Only a completed Task can be reopened.');
            }
            foreach ($task->assignments()->lockForUpdate()->where('status', 'completed')->get() as $assignment) {
                $assignment->forceFill(['status' => TaskAssignmentStatus::InProgress, 'completed_at' => null])->save();
                $this->assignmentEvent($assignment, 'reopened', $actor, TaskAssignmentStatus::Completed, TaskAssignmentStatus::InProgress, $reason);
            }
            $task->forceFill(['status' => TaskStatus::InProgress, 'completed_at' => null])->save();
            $this->event($task, TaskEventType::Reopened, $actor, TaskStatus::Completed, TaskStatus::InProgress, trim($reason));

            return $this->load($task->refresh());
        });
    }

    public function comment(Task $task, string $comment, User $actor): Task
    {
        if ($this->workerAssignment($task, $actor) === null && ! $this->authorization->isAssignee($actor, $task)) {
            throw new AuthorizationException;
        }
        if (blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'A Task update is required.']);
        }

        return DB::transaction(function () use ($task, $comment, $actor): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $this->event($task, TaskEventType::CommentAdded, $actor, null, null, trim($comment));

            return $this->load($task->refresh());
        });
    }

    /** @param array<int, TaskAssignmentStatus> $fromStates */
    private function workerTransition(Task $task, User $actor, array $fromStates, TaskAssignmentStatus $to, string $event, ?string $note = null, array $changes = []): Task
    {
        $assignment = $this->workerAssignment($task, $actor);
        if ($assignment === null) {
            if ($this->authorization->isAssignee($actor, $task)) {
                return $this->legacyWorkerTransition($task, $actor, $fromStates, $to, $event, $note, $changes);
            }
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($task, $assignment, $fromStates, $to, $event, $note, $changes, $actor): Task {
            $assignment = TaskAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            if ($assignment->status === $to) {
                return $this->load(Task::query()->findOrFail($task->id));
            }
            if (! in_array($assignment->status, $fromStates, true)) {
                throw new TaskException('This Task action is not available in the current state.');
            }
            if ($assignment->completionSubmissions()->where('status', 'pending')->exists()) {
                throw new TaskException('This Task is awaiting completion confirmation.');
            }
            $from = $assignment->status;
            foreach ($changes as $field => $value) {
                $assignment->{$field} = $value;
            }
            if ($to === TaskAssignmentStatus::InProgress) {
                $assignment->started_at ??= now();
            }
            $assignment->status = $to;
            $assignment->save();
            $this->assignmentEvent($assignment, $event, $actor, $from, $to, $note);
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $before = $task->status;
            $this->syncParent($task);
            $parentEvent = match ($event) {
                'started' => TaskEventType::Started,
                'waiting' => TaskEventType::Waiting,
                default => TaskEventType::Resumed,
            };
            $this->event($task, $parentEvent, $actor, $before, $task->status, $note);

            return $this->load($task->refresh());
        });
    }

    /** @param array<int, TaskAssignmentStatus> $fromStates */
    private function legacyWorkerTransition(Task $task, User $actor, array $fromStates, TaskAssignmentStatus $to, string $event, ?string $note, array $changes): Task
    {
        $allowed = array_map(fn (TaskAssignmentStatus $status): string => $status->value, $fromStates);

        return DB::transaction(function () use ($task, $actor, $allowed, $to, $event, $note, $changes): Task {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $legacyStatus = $task->status === TaskStatus::Pending ? 'assigned' : $task->status->value;
            if ($legacyStatus === $to->value) {
                return $this->load($task);
            }
            if (! in_array($legacyStatus, $allowed, true)) {
                throw new TaskException('This Task action is not available in the current state.');
            }
            if ($task->completionSubmissions()->where('status', 'pending')->exists()) {
                throw new TaskException('This Task is awaiting completion confirmation.');
            }
            $from = $task->status;
            foreach ($changes as $field => $value) {
                $task->{$field} = $value;
            }
            $task->status = TaskStatus::from($to->value);
            if ($to === TaskAssignmentStatus::InProgress) {
                $task->started_at ??= now();
            }
            $task->save();
            $parentEvent = match ($event) {
                'started' => TaskEventType::Started,
                'waiting' => TaskEventType::Waiting,
                default => TaskEventType::Resumed,
            };
            $this->event($task, $parentEvent, $actor, $from, $task->status, $note);

            return $this->load($task->refresh());
        });
    }

    private function workerAssignment(Task $task, User $actor): ?TaskAssignment
    {
        return $this->authorization->assignmentFor($actor, $task);
    }

    private function syncParent(Task $task): void
    {
        $assignments = $task->assignments()->whereNotIn('status', ['removed', 'cancelled'])->get();
        if ($assignments->isEmpty()) {
            $task->status = $task->assigned_team_id === null ? TaskStatus::Pending : TaskStatus::Assigned;
        } elseif ($assignments->every(fn (TaskAssignment $assignment): bool => $assignment->status === TaskAssignmentStatus::Completed)) {
            $task->status = TaskStatus::Completed;
            $task->completed_at = now();
        } elseif ($assignments->contains('status', TaskAssignmentStatus::Waiting)) {
            $task->status = TaskStatus::Waiting;
            $task->completed_at = null;
        } elseif ($assignments->contains('status', TaskAssignmentStatus::InProgress)) {
            $task->status = TaskStatus::InProgress;
            $task->started_at ??= now();
            $task->completed_at = null;
        } else {
            $task->status = TaskStatus::Assigned;
            $task->completed_at = null;
        }
        $task->save();
    }

    private function createAssignment(Task $task, int $employeeId, User $actor): TaskAssignment
    {
        $employee = Employee::query()->with('team:id,name')->findOrFail($employeeId);
        $assignment = TaskAssignment::query()->create([
            'task_id' => $task->id,
            'employee_id' => $employee->id,
            'team_id_at_assignment' => $employee->team_id,
            'team_name_at_assignment' => $employee->team?->name,
            'status' => TaskAssignmentStatus::Assigned,
            'assigned_by_user_id' => $actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'backfilled_from_legacy' => false,
        ]);
        $this->assignmentEvent($assignment, 'assigned', $actor, null, TaskAssignmentStatus::Assigned);

        return $assignment;
    }

    /** @param array<int, int|string> $ids @return array<int, int> */
    private function employeeIds(array $ids, ?int $legacyId = null): array
    {
        if ($legacyId !== null) {
            $ids[] = $legacyId;
        }

        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id): bool => filled($id)))));
    }

    /** @param array<int, int> $employeeIds */
    private function validateAssignment(array $employeeIds, ?int $teamId, User $actor): void
    {
        if ($employeeIds !== [] && $teamId !== null) {
            throw ValidationException::withMessages(['assigned_employee_ids' => 'Assign the Task to Employees or a Team, not both.']);
        }
        if ($employeeIds !== []) {
            $employees = Employee::query()->whereIn('id', $employeeIds)->where('status', true)->whereNotNull('user_id')->with('user')->get();
            if ($employees->count() !== count($employeeIds)) {
                throw ValidationException::withMessages(['assigned_employee_ids' => 'Select active Employees with login accounts.']);
            }
            foreach ($employees as $employee) {
                if (! $this->authorization->allows($employee->user, TaskPermission::View)) {
                    throw ValidationException::withMessages(['assigned_employee_ids' => "{$employee->name} is not eligible for Tasks."]);
                }
                if (! $this->authorization->canAssignEmployee($actor, $employee)) {
                    throw new AuthorizationException;
                }
            }
        }
        if ($teamId !== null) {
            $team = Team::query()->whereKey($teamId)->where('status', true)->first();
            if ($team === null) {
                throw ValidationException::withMessages(['assigned_team_id' => 'Select an active Team.']);
            }
            if (! $this->authorization->canAssignTeam($actor, $team)) {
                throw new AuthorizationException;
            }
        }
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array{0: array<int, int>, 1: ?int, 2: TaskAssignmentMode}
     */
    private function resolveAssignmentMode(?TaskAssignmentMode $mode, array $employeeIds, ?int $teamId, User $actor): array
    {
        if ($mode === null && $employeeIds === [] && $teamId === null) {
            return [[], null, TaskAssignmentMode::SingleEmployee];
        }

        $mode ??= $teamId !== null
            ? TaskAssignmentMode::TeamQueue
            : (count($employeeIds) > 1 ? TaskAssignmentMode::MultipleEmployees : TaskAssignmentMode::SingleEmployee);

        return match ($mode) {
            TaskAssignmentMode::SingleEmployee => count($employeeIds) === 1
                ? [$employeeIds, null, $mode]
                : throw ValidationException::withMessages(['assigned_employee_id' => 'Select one Employee.']),
            TaskAssignmentMode::MultipleEmployees => count($employeeIds) >= 2
                ? [$employeeIds, null, $mode]
                : throw ValidationException::withMessages(['assigned_employee_ids' => 'Select at least two Employees.']),
            TaskAssignmentMode::EntireTeam => $this->resolveEntireTeam($teamId, $actor, $mode),
            TaskAssignmentMode::TeamQueue => $teamId !== null
                ? [[], $teamId, $mode]
                : throw ValidationException::withMessages(['assigned_team_id' => 'Select a Team for the Team Queue.']),
        };
    }

    /** @return array{0: array<int, int>, 1: null, 2: TaskAssignmentMode} */
    private function resolveEntireTeam(?int $teamId, User $actor, TaskAssignmentMode $mode): array
    {
        if ($teamId === null) {
            throw ValidationException::withMessages(['assigned_team_id' => 'Select the Team whose eligible Employees should receive this Task.']);
        }
        $team = Team::query()->whereKey($teamId)->where('status', true)->first();
        if ($team === null) {
            throw ValidationException::withMessages(['assigned_team_id' => 'Select an active Team.']);
        }
        if (! $this->authorization->canAssignTeam($actor, $team)) {
            throw new AuthorizationException;
        }
        $employeeIds = $this->assignees->eligibleTeamMemberIds($teamId, $actor);
        if ($employeeIds === []) {
            throw ValidationException::withMessages(['assigned_team_id' => 'This Team has no active eligible Employees.']);
        }

        return [$employeeIds, null, $mode];
    }

    private function assignmentEvent(TaskAssignment $assignment, string $type, User $actor, ?TaskAssignmentStatus $from, ?TaskAssignmentStatus $to, ?string $note = null): void
    {
        TaskAssignmentEvent::query()->create([
            'task_assignment_id' => $assignment->id,
            'event_type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_user_id' => $actor->id,
            'note' => $note,
            'idempotency_key' => (string) Str::uuid(),
            'backfilled_from_legacy' => false,
            'occurred_at' => now(),
        ]);
    }

    private function completionEvent(TaskCompletionSubmission $submission, string $type, User $actor, ?string $reason = null): void
    {
        TaskCompletionSubmissionEvent::query()->create([
            'task_completion_submission_id' => $submission->id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'reason' => $reason,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }

    private function event(Task $task, TaskEventType $type, User $actor, ?TaskStatus $from = null, ?TaskStatus $to = null, ?string $note = null, ?array $old = null, ?array $new = null): void
    {
        TaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->id,
            'note' => $note,
            'old_values' => $old,
            'new_values' => $new,
            'idempotency_key' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }

    private function load(Task $task): Task
    {
        return $task->load([
            'assignments.employee.user',
            'assignments.completionSubmissions',
            'completionSubmissions',
            'assignedEmployee.user',
            'assignedTeam',
            'createdBy',
            'events.actor',
        ]);
    }
}
