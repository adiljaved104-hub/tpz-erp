<?php

namespace App\Console\Commands;

use App\Enums\TaskAssignmentStatus;
use App\Models\TaskAssignment;
use App\Services\Notifications\TaskNotificationDispatcher;
use App\Services\Operations\BackgroundServiceHealth;
use Illuminate\Console\Command;

class SendTaskDueNotifications extends Command
{
    protected $signature = 'tasks:send-due-notifications';

    protected $description = 'Send idempotent due-soon and overdue Task notifications';

    public function handle(TaskNotificationDispatcher $notifications, BackgroundServiceHealth $health): int
    {
        $now = now();
        $sent = 0;

        TaskAssignment::query()
            ->whereIn('status', [
                TaskAssignmentStatus::Assigned->value,
                TaskAssignmentStatus::InProgress->value,
                TaskAssignmentStatus::Waiting->value,
            ])
            ->whereHas('task', fn ($query) => $query
                ->active()
                ->whereNotNull('due_at')
                ->where('due_at', '<=', $now->copy()->addHours(24)))
            ->whereDoesntHave('completionSubmissions', fn ($query) => $query->where('status', 'pending'))
            ->with(['task', 'employee.user'])
            ->chunkById(200, function ($assignments) use ($notifications, $now, &$sent): void {
                foreach ($assignments as $assignment) {
                    if ($notifications->due($assignment, $assignment->task->due_at->lt($now))) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} Task due notification(s).");
        $health->record(BackgroundServiceHealth::TASK_EVALUATOR_SUCCESS);

        return self::SUCCESS;
    }
}
