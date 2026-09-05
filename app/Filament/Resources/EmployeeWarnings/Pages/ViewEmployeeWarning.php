<?php

namespace App\Filament\Resources\EmployeeWarnings\Pages;

use App\Enums\HrPermission;
use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Hr\EmployeeWarningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEmployeeWarning extends ViewRecord
{
    protected static string $resource = EmployeeWarningResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(EmployeeWarningService::class)->markRead($this->record, auth()->user());
        $this->record->load(['employee', 'category', 'issuedBy', 'acknowledgments']);
    }

    protected function getHeaderActions(): array
    {
        $ack = $this->record->acknowledgments->firstWhere('employee_id', auth()->user()?->employee?->id);

        return [
            Action::make('acknowledge')->label('Acknowledge Warning')->color('success')->requiresConfirmation()
                ->modalDescription('Acknowledgment confirms that you received and read this Warning. It does not mean you agree with it.')
                ->visible(fn (): bool => $this->record->acknowledgment_required && $ack?->acknowledged_at === null && auth()->user()?->employee?->id === $this->record->employee_id)
                ->action(function (): void {
                    $this->record = app(EmployeeWarningService::class)->acknowledge($this->record, auth()->user());
                    Notification::make()->success()->title('Warning acknowledged')->send();
                }),
            Action::make('close')->label('Close Warning')->color('warning')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === 'active' && app(HrRecordAuthorization::class)->allows(auth()->user(), HrPermission::WarningManage))
                ->action(function (): void {
                    $this->record = app(EmployeeWarningService::class)->close($this->record, auth()->user());
                    Notification::make()->success()->title('Warning closed')->send();
                }),
        ];
    }
}
