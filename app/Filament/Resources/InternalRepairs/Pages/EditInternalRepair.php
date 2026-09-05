<?php

namespace App\Filament\Resources\InternalRepairs\Pages;

use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Models\WarrantyRepair;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditInternalRepair extends EditRecord
{
    protected static string $resource = InternalRepairResource::class;

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
            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (WarrantyRepairException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Internal Repair could not be updated')->body($exception->getMessage())->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return InternalRepairResource::getUrl('view', ['record' => $this->record]);
    }
}
