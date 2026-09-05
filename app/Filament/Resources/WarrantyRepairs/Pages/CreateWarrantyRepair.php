<?php

namespace App\Filament\Resources\WarrantyRepairs\Pages;

use App\Enums\WarrantyRepairSource;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateWarrantyRepair extends CreateRecord
{
    protected static string $resource = WarrantyRepairResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(WarrantyRepairService::class)->create($data + ['source' => WarrantyRepairSource::Manual->value, 'idempotency_key' => (string) Str::uuid()], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }
            $this->dispatch('form-validation-error', livewireId: $this->getId());
            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (WarrantyRepairException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Warranty / Repair could not be created')->body($exception->getMessage() ?: 'You are not authorized to create this case.')->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return WarrantyRepairResource::getUrl('view', ['record' => $this->record]);
    }
}
