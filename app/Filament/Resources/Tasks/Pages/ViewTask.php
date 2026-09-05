<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskPermission;
use App\Enums\TaskStatus;
use App\Exceptions\TaskException;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\TaskCompletionSubmission;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Tasks\TaskService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        $authorization = app(TaskAuthorization::class);
        $user = auth()->user();
        $assignment = $authorization->assignmentFor($user, $this->record);
        $legacyAssignee = $assignment === null && $authorization->isAssignee($user, $this->record);
        $awaiting = $assignment?->completionSubmissions()->where('status', 'pending')->exists() === true;
        if ($legacyAssignee) {
            $awaiting = $this->record->completionSubmissions()->whereNull('task_assignment_id')->where('status', 'pending')->exists();
        }
        $workerStatus = $assignment?->status->value ?? ($legacyAssignee ? $this->record->status->value : null);
        $actions = [
            OpenChatDiscussionAction::make($this->record),
            EditAction::make()->visible(fn (): bool => TaskResource::canEdit($this->record)),
            Action::make('start')->label('Start Task')->visible(fn (): bool => in_array($workerStatus, ['pending', 'assigned'], true) && ! $awaiting)->action(fn () => $this->run('start')),
            Action::make('waiting')->label('Mark Waiting')->visible(fn (): bool => $workerStatus === 'in_progress' && ! $awaiting)->schema([Textarea::make('reason')->required(), DateTimePicker::make('follow_up_at')->label('Follow-up At')->seconds(false)])->action(fn (array $data) => $this->run('wait', $data)),
            Action::make('resume')->label('Resume Task')->visible(fn (): bool => $workerStatus === 'waiting' && ! $awaiting)->action(fn () => $this->run('resume')),
            Action::make('submit_completion')->label('Submit for Completion')->color('success')->visible(fn (): bool => in_array($workerStatus, ['in_progress', 'waiting'], true) && ! $awaiting)->schema([Textarea::make('note')->label('Completion Note')])->action(fn (array $data) => $this->run('submit', $data)),
            Action::make('comment')->label('Add Update')->visible(fn (): bool => in_array($workerStatus, ['assigned', 'in_progress', 'waiting'], true) && ! $awaiting)->schema([Textarea::make('comment')->label('Task Update')->required()])->action(fn (array $data) => $this->run('comment', $data)),
            Action::make('reopen')->label('Reopen')->visible(fn (): bool => $this->record->status === TaskStatus::Completed && app(TaskAuthorization::class)->canReopen(auth()->user(), $this->record))->schema([Textarea::make('reason')->required()])->action(fn (array $data) => $this->run('reopen', $data)),
            Action::make('cancel')->color('danger')->visible(fn (): bool => auth()->user()->can(TaskPermission::Cancel->value, $this->record) && ! $this->record->status->isTerminal())->schema([Textarea::make('reason')->required()])->action(fn (array $data) => $this->run('cancel', $data)),
        ];

        $pending = $this->record->completionSubmissions()->where('status', 'pending')->with('assignment.employee:id,name')->get();
        foreach ($pending as $submission) {
            if (! $authorization->canSupervise($user, $this->record)) {
                continue;
            }
            $suffix = $pending->count() > 1 ? ' — '.$submission->assignment->employee->name : '';
            $actions[] = Action::make('confirm_completion_'.$submission->id)->label('Confirm Completion'.$suffix)->color('success')
                ->requiresConfirmation()->action(fn () => $this->decide($submission->id, true));
            $actions[] = Action::make('return_completion_'.$submission->id)->label('Return to Employee'.$suffix)->color('warning')
                ->schema([Textarea::make('reason')->label('Reason')->required()])
                ->action(fn (array $data) => $this->decide($submission->id, false, $data['reason']));
        }

        return $actions;
    }

    private function run(string $action, array $data = []): void
    {
        try {
            $service = app(TaskService::class);
            $user = auth()->user();
            $this->record = match ($action) {
                'start' => $service->start($this->record, $user), 'wait' => $service->wait($this->record, $data['reason'], $data['follow_up_at'] ?? null, $user),
                'resume' => $service->resume($this->record, $user), 'submit' => $service->submitForCompletion($this->record, $data['note'] ?? null, $user),
                'cancel' => $service->cancel($this->record, $data['reason'], $user), 'reopen' => $service->reopen($this->record, $data['reason'], $user),
                'comment' => $service->comment($this->record, $data['comment'], $user),
            };
            $this->record->load(['events.actor', 'assignedEmployee', 'assignedTeam', 'createdBy']);
            Notification::make()->success()->title('Task updated')->send();
        } catch (TaskException|ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Task could not be updated')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this Task.'))->send();
            throw new Halt;
        }
    }

    private function decide(int $submissionId, bool $confirm, ?string $reason = null): void
    {
        try {
            $submission = TaskCompletionSubmission::query()->findOrFail($submissionId);
            $this->record = $confirm
                ? app(TaskService::class)->confirmCompletion($submission, auth()->user())
                : app(TaskService::class)->returnToEmployee($submission, (string) $reason, auth()->user());
            Notification::make()->success()->title($confirm ? 'Completion confirmed' : 'Task returned to Employee')->send();
        } catch (TaskException|ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Completion decision failed')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to decide this submission.'))->send();
            throw new Halt;
        }
    }
}
