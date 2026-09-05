<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Enums\TaskLinkedType;
use App\Exceptions\ComplaintException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Services\ServiceCases\ComplaintService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ViewComplaint extends ViewRecord
{
    protected static string $resource = ComplaintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::Complaint, $this->record),
            Action::make('inProgress')->label('Start Work')->visible(fn () => $this->record->status === ComplaintStatus::Open)->action(fn () => $this->run(ComplaintStatus::InProgress)),
            Action::make('resolve')->label('Resolve')->schema([Select::make('resolution')->options(ComplaintResolution::class)->required(), Textarea::make('note')->label('Resolution Note')])->visible(fn () => ! in_array($this->record->status, [ComplaintStatus::Resolved, ComplaintStatus::Closed, ComplaintStatus::Cancelled], true))->action(fn (array $data) => $this->run(ComplaintStatus::Resolved, $data['note'] ?? null, ComplaintResolution::from($data['resolution']))),
            Action::make('cancel')->label('Cancel')->color('danger')->schema([Textarea::make('reason')->required()])->visible(fn () => ! in_array($this->record->status, [ComplaintStatus::Closed, ComplaintStatus::Cancelled], true))->action(fn (array $data) => $this->run(ComplaintStatus::Cancelled, $data['reason'])),
            Action::make('close')->visible(fn () => $this->record->status === ComplaintStatus::Resolved)->action(fn () => $this->run(ComplaintStatus::Closed)),
        ];
    }

    private function run(ComplaintStatus $to, ?string $note = null, ?ComplaintResolution $resolution = null): void
    {
        try {
            $this->record = app(ComplaintService::class)->transition($this->record, $to, auth()->user(), $resolution, $note);
            Notification::make()->success()->title('Complaint updated')->send();
        } catch (ComplaintException|ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Complaint could not be updated')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this Complaint.'))->send();
            throw new Halt;
        }
    }
}
