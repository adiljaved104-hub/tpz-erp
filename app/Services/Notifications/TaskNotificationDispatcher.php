<?php

namespace App\Services\Notifications;

use App\Enums\EmployeeRole;
use App\Enums\TaskPermission;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletionSubmission;
use App\Models\User;
use App\Notifications\TaskDatabaseNotification;
use App\Services\Authorization\TaskAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class TaskNotificationDispatcher
{
    public function __construct(
        private readonly TaskAuthorization $authorization,
        private readonly CriticalAlertDispatcher $criticalAlerts,
        private readonly NotificationRuleService $rules,
    ) {}

    public function assigned(TaskAssignment $assignment, bool $reassigned = false): void
    {
        $assignment->loadMissing(['task', 'employee.user']);
        $recipient = $assignment->employee?->user;
        if (! $recipient instanceof User) {
            return;
        }

        $this->send(
            $recipient,
            $reassigned ? 'task.reassigned' : 'task.assigned',
            ($reassigned ? 'reassigned:' : 'assigned:').$assignment->id.':'.$assignment->updated_at?->toJSON(),
            $reassigned ? 'Task Reassigned to You' : 'New Task Assigned',
            $this->taskMessage($assignment->task),
            $assignment->task,
            true,
        );
    }

    public function awaitingConfirmation(TaskCompletionSubmission $submission): void
    {
        $submission->loadMissing(['task.assignments', 'assignment.employee.user']);
        $task = $submission->task;
        $employeeName = $submission->assignment?->employee?->name ?? $submission->submittedBy?->name ?? 'An employee';

        $assignee = $submission->assignment?->employee?->user;
        if ($assignee instanceof User) {
            $this->send(
                $assignee,
                'task.awaiting_confirmation',
                'awaiting-assignee:'.$submission->id,
                'Task Submitted for Confirmation',
                $task->reference.' — your completion is awaiting confirmation.',
                $task,
            );
        }

        $this->approvers($task)->each(function (User $recipient) use ($submission, $task, $employeeName): void {
            $this->send(
                $recipient,
                'task.awaiting_confirmation',
                'awaiting:'.$submission->id,
                'Task Awaiting Confirmation',
                Str::limit($employeeName.' submitted '.$task->reference.' — '.$task->title, 180),
                $task,
                true,
            );
        });
    }

    public function returned(TaskCompletionSubmission $submission): void
    {
        $submission->loadMissing(['task', 'assignment.employee.user', 'submittedBy.employee']);
        $recipient = $submission->assignment?->employee?->user ?? $submission->submittedBy;
        if (! $recipient instanceof User) {
            return;
        }

        $reason = trim((string) $submission->decision_reason);
        $message = 'Your work on '.$submission->task->reference.' was returned.';
        if ($reason !== '') {
            $message .= ' Reason: '.Str::limit($reason, 140);
        }

        $this->send($recipient, 'task.returned', 'returned:'.$submission->id, 'Task Returned', $message, $submission->task, true);
    }

    public function completionConfirmed(TaskCompletionSubmission $submission): void
    {
        $submission->loadMissing(['task', 'assignment.employee.user', 'submittedBy.employee']);
        $recipient = $submission->assignment?->employee?->user ?? $submission->submittedBy;
        if (! $recipient instanceof User) {
            return;
        }

        $this->send(
            $recipient,
            'task.completion_confirmed',
            'confirmed:'.$submission->id,
            'Task Completion Confirmed',
            'Your completion of '.$submission->task->reference.' — '.$submission->task->title.' was confirmed.',
            $submission->task,
        );
    }

    public function due(TaskAssignment $assignment, bool $overdue): bool
    {
        $assignment->loadMissing(['task', 'employee.user']);
        $recipient = $assignment->employee?->user;
        $task = $assignment->task;
        if (! $recipient instanceof User || $task->due_at === null) {
            return false;
        }

        return $this->send(
            $recipient,
            $overdue ? 'task.overdue' : 'task.due_soon',
            ($overdue ? 'overdue:' : 'due-soon:').$assignment->id.':'.$task->due_at->toJSON(),
            $overdue ? 'Task Overdue' : 'Task Due Soon',
            $task->reference.' — '.$task->title.' · Due '.$task->due_at->format('d M, g:i A'),
            $task,
        );
    }

    /** @return Collection<int, User> */
    private function approvers(Task $task): Collection
    {
        $strategy = $this->rules->recipientStrategy('task.awaiting_confirmation');
        if ($strategy === 'task_creator') {
            $task->loadMissing('createdBy.employee');
            $creator = $task->createdBy;

            return $creator instanceof User && $creator->employee?->status === true && $this->authorization->canSupervise($creator, $task)
                ? collect([$creator])
                : collect();
        }

        return Employee::query()
            ->where('status', true)
            ->whereNotNull('user_id')
            ->whereIn('role', [EmployeeRole::Owner->value, EmployeeRole::Admin->value, EmployeeRole::Manager->value])
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter(fn ($user): bool => $user instanceof User
                && ($strategy !== 'owner_admin_fallback' || in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true))
                && $this->authorization->canSupervise($user, $task))
            ->unique('id')
            ->values();
    }

    private function taskMessage(Task $task): string
    {
        $message = $task->reference.' — '.$task->title;
        if ($task->due_at !== null) {
            $message .= ' · Due '.$task->due_at->format('d M, g:i A');
        }

        return Str::limit($message, 180);
    }

    private function send(User $recipient, string $type, string $eventKey, string $title, string $message, Task $task, bool $sound = false): bool
    {
        if ($recipient->employee?->status !== true || ! $this->authorization->allows($recipient, TaskPermission::View, $task) || ! $this->rules->enabled($type)) {
            return false;
        }

        $id = $this->deterministicId($type.':'.$eventKey.':user:'.$recipient->id);
        $inApp = $this->rules->channelEnabled($type, 'in_app');
        $email = $this->rules->channelEnabled($type, 'email')
            && in_array($type, ['task.assigned', 'task.reassigned', 'task.awaiting_confirmation', 'task.overdue'], true);
        $delivered = false;

        if ($inApp) {
            if ($recipient->notifications()->whereKey($id)->exists()) {
                return false;
            }

            try {
                $recipient->notify(new TaskDatabaseNotification($id, $type, [
                    'category' => 'task',
                    'event' => $type,
                    'title' => $title,
                    'message' => $message,
                    'target_type' => 'task',
                    'target_id' => $task->id,
                    'task_reference' => $task->reference,
                    'sound' => $sound,
                ]));
                $delivered = true;
            } catch (QueryException $exception) {
                if ($recipient->notifications()->whereKey($id)->exists()) {
                    return false;
                }
                report($exception);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($email && ($inApp || $this->rules->claimEmailDelivery($type, $eventKey, $recipient))) {
            $delivered = $this->criticalAlerts->queueEmail(
                $recipient,
                '[ERP] '.$title.' — '.$task->reference,
                $title,
                $task->reference,
                $message,
                $task->status->getLabel(),
                TaskResource::getUrl('view', ['record' => $task]),
                'task',
                (int) $task->id,
                [],
                $type,
            ) || $delivered;
        }

        return $delivered;
    }

    private function deterministicId(string $key): string
    {
        $hex = hash('sha256', 'tpz-erp-notification:'.$key);
        $variant = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-'.$variant.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
