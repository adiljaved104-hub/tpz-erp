<?php

namespace App\Filament\Resources\HrNotices\Pages;

use App\Enums\HrPermission;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Models\Employee;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\HrNoticeService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewHrNotice extends ViewRecord
{
    protected static string $resource = HrNoticeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(HrNoticeService::class)->markRead($this->record, auth()->user());
        $this->record->load(['category', 'template', 'team', 'publishedBy', 'recipients.employee', 'acknowledgments']);
    }

    public function canViewRecipientStatus(): bool
    {
        return app(HrRecordAuthorization::class)->canViewNoticeRecipientStatus(auth()->user());
    }

    public function canLinkRecipientEmployees(): bool
    {
        return auth()->user()?->can('viewAny', Employee::class) === true;
    }

    protected function getHeaderActions(): array
    {
        $employeeId = auth()->user()?->employee?->id;
        $isRecipient = $employeeId !== null && $this->record->recipients->contains('employee_id', $employeeId);
        $ack = $this->record->acknowledgments->firstWhere('employee_id', auth()->user()?->employee?->id);

        return [
            Action::make('acknowledge')->label('Acknowledge Notice')->color('success')->requiresConfirmation()
                ->modalDescription('Acknowledgment confirms that you received and read this Notice.')
                ->visible(fn (): bool => $isRecipient && $this->record->acknowledgment_required && $ack?->acknowledged_at === null)
                ->action(function (): void {
                    $this->record = app(HrNoticeService::class)->acknowledge($this->record, auth()->user());
                    Notification::make()->success()->title('Notice acknowledged')->send();
                }),
            Action::make('archive')->label('Archive Notice')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === 'active' && app(HrRecordAuthorization::class)->allows(auth()->user(), HrPermission::NoticeManage))
                ->action(function (): void {
                    $this->record = app(HrNoticeService::class)->archive($this->record, auth()->user());
                    Notification::make()->success()->title('Notice archived')->send();
                }),
        ];
    }
}
