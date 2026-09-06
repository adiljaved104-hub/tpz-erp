<?php

namespace App\Services\DemoData\Scenarios;

use App\DTOs\Tasks\CreateTaskData;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\TaskAssignmentMode;
use App\Enums\TaskPriority;
use App\Models\Expense;
use App\Models\Task;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoIdentity;
use App\Services\ExpenseService;
use App\Services\Tasks\TaskService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use RuntimeException;

final class OperationsScenarioBuilder
{
    public function __construct(
        private readonly DemoIdentity $identity,
        private readonly TaskService $tasks,
        private readonly ExpenseService $expenses,
    ) {}

    public function build(DemoContext $context): void
    {
        $this->tasks($context);
        $this->expenses($context);

        $context->count('tasks', Task::query()->where('title', 'like', $this->identity->marker().'%')->count());
        $context->count('expenses', Expense::query()->where('reference_note', 'like', $this->identity->marker().'%')->count());
    }

    private function tasks(DemoContext $context): void
    {
        $employeeKeys = ['staff1', 'staff2', 'staff3', 'manager1', 'manager2'];
        foreach (range(1, 16) as $number) {
            $key = $this->identity->uuid('task/'.$number);
            $task = Task::query()->where('idempotency_key', $key)->with(['assignments.employee.user', 'completionSubmissions'])->first();
            $employee = $context->employees[$employeeKeys[($number - 1) % count($employeeKeys)]];
            $createdAt = CarbonImmutable::today()->subDays(35 - ($number * 2))->setTime(9, 0);
            $dueAt = match (true) {
                $number <= 4 => $createdAt->addDays(4),
                $number <= 7 => CarbonImmutable::now()->subDays($number - 3),
                default => CarbonImmutable::now()->addDays(($number % 5) + 1),
            };

            if ($task === null) {
                $mode = $number === 14 ? TaskAssignmentMode::TeamQueue : ($number === 13 ? TaskAssignmentMode::MultipleEmployees : TaskAssignmentMode::SingleEmployee);
                $ids = $mode === TaskAssignmentMode::MultipleEmployees
                    ? [$context->employees['staff1']->id, $context->employees['staff2']->id]
                    : ($mode === TaskAssignmentMode::SingleEmployee ? [$employee->id] : []);
                $teamId = $mode === TaskAssignmentMode::TeamQueue ? $context->teams['operations']->id : null;
                $task = $this->at($createdAt, fn (): Task => $this->tasks->create(new CreateTaskData(
                    title: $this->identity->note(sprintf('Operational task %02d', $number)),
                    description: $this->identity->note('Deterministic task lifecycle fixture.'),
                    priority: match ($number % 4) {
                        0 => TaskPriority::Critical, 1 => TaskPriority::High, 2 => TaskPriority::Normal, default => TaskPriority::Low,
                    },
                    assignedEmployeeId: null,
                    assignedTeamId: $teamId,
                    dueAt: $dueAt->toDateTimeString(),
                    followUpAt: null,
                    linkedType: null,
                    linkedRecordId: null,
                    idempotencyKey: $key,
                    assignedEmployeeIds: $ids,
                    assignmentMode: $mode,
                ), $context->owner));
            }

            if ($number === 14) {
                continue;
            }
            $worker = $task->assignments()->with('employee.user')->orderBy('id')->first()?->employee?->user;
            if ($worker === null) {
                throw new RuntimeException('A demo Task is missing its worker account.');
            }
            if ($number <= 11 || $number === 16) {
                $assignment = $task->assignments()->where('employee_id', $worker->employee->id)->firstOrFail();
                if ($assignment->status->value === 'assigned') {
                    $task = $this->at($createdAt->addHour(), fn (): Task => $this->tasks->start($task, $worker));
                }
            }
            if ($number >= 9 && $number <= 11) {
                $assignment = $task->assignments()->where('employee_id', $worker->employee->id)->firstOrFail();
                if ($assignment->status->value === 'in_progress') {
                    $task = $this->tasks->wait($task, 'Waiting for a deterministic demo dependency.', CarbonImmutable::now()->addDay()->toDateTimeString(), $worker);
                }
            }
            if ($number <= 4 || $number === 16) {
                $assignment = $task->assignments()->where('employee_id', $worker->employee->id)->firstOrFail();
                if (in_array($assignment->status->value, ['in_progress', 'waiting'], true)
                    && ! $assignment->completionSubmissions()->where('status', 'pending')->exists()) {
                    $task = $this->tasks->submitForCompletion($task, $this->identity->note('Employee completion submission fixture.'), $worker);
                }
                if ($number <= 4) {
                    $submission = $task->completionSubmissions()->where('status', 'pending')->first();
                    if ($submission !== null) {
                        $task = $this->tasks->confirmCompletion($submission, $context->owner);
                    }
                }
            }
            if ($number === 15 && ! $task->status->isTerminal()) {
                $this->tasks->cancel($task, 'Demo task cancelled by supervisor.', $context->owner);
            }
        }
    }

    private function expenses(DemoContext $context): void
    {
        $categories = ExpenseCategory::cases();
        foreach (range(1, 24) as $number) {
            $marker = $this->identity->note(sprintf('EXP-%03d', $number));
            $expense = Expense::query()->where('reference_note', $marker)->first();
            if ($expense !== null) {
                $this->assert($expense->description === sprintf('Demo operating expense %03d', $number), "Demo Expense [{$number}] has an unexpected fingerprint.");

                continue;
            }
            $date = CarbonImmutable::today()->subDays(($number * 5) % 88)->setTime(12, 0);
            $this->at($date, fn () => $this->expenses->create([
                'expense_date' => $date->toDateString(),
                'category' => $categories[($number - 1) % count($categories)],
                'description' => sprintf('Demo operating expense %03d', $number),
                'amount' => number_format(75 + ($number * 13.5), 2, '.', ''),
                'cost_center' => $number % 3 === 0 ? ExpenseCostCenter::Marketplace : ExpenseCostCenter::WebSales,
                'employee_id' => $number % 4 === 0 ? $context->employees['staff1']->id : null,
                'reference_note' => $marker,
            ], $context->owner));
        }
    }

    private function at(CarbonImmutable $when, Closure $callback): mixed
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($when);
        Carbon::setTestNow($when);
        try {
            return $callback();
        } finally {
            CarbonImmutable::setTestNow($previous);
            Carbon::setTestNow($previous);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
