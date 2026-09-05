<?php

namespace App\Filament\Resources\WarrantyRepairs\Pages;

use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\WarrantyRepair;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditWarrantyRepair extends EditRecord
{
    protected static string $resource = WarrantyRepairResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var WarrantyRepair $record */
        try {
            return app(WarrantyRepairService::class)->updateOperationalDetails($record, $data, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }
            $this->dispatch('form-validation-error', livewireId: $this->getId());
            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (WarrantyRepairException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Warranty / Repair could not be updated')->body($exception->getMessage() ?: 'You are not authorized to edit this case.')->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return WarrantyRepairResource::getUrl('view', ['record' => $this->record]);
    }
}
