<?php

namespace App\Filament\Resources\CustomerReturns\Pages;

use App\Enums\CustomerReturnPermission;
use App\Exceptions\CustomerReturnException;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturn;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\CustomerReturnUnifiedWorkflowService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateCustomerReturn extends CreateRecord
{
    protected static string $resource = CustomerReturnResource::class;

    protected static bool $canCreateAnother = false;

    public bool $savePending = false;

    public string $submissionKey;

    public function mount(): void
    {
        $this->submissionKey = (string) str()->uuid();
        parent::mount();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Create + Receive + Complete QC')
            ->visible(fn (): bool => app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Receive)
                && app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Inspect));
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('savePending')->label('Save Pending')->color('gray')->action('savePending'),
            $this->getCancelFormAction(),
        ];
    }

    public function savePending(): void
    {
        $this->savePending = true;
        $this->create();
    }

    protected function handleRecordCreation(array $data): CustomerReturn
    {
        try {
            return app(CustomerReturnUnifiedWorkflowService::class)->submit(
                $data, auth()->user(), ! $this->savePending, $this->submissionKey,
            );
        } catch (CustomerReturnException $exception) {
            Notification::make()->danger()->title('Cannot process return')->body($exception->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->savePending
            ? 'Return saved. Complete the receipt and QC when ready.'
            : 'Return received and QC recorded. Any wrong or different item is waiting for Owner/Admin review.';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
