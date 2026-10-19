<?php

namespace App\Filament\Resources\WarehouseStockVisibilityGrants\Pages;

use App\Filament\Resources\WarehouseStockVisibilityGrants\WarehouseStockVisibilityGrantResource;
use App\Services\Responsibilities\WarehouseStockVisibilityGrantService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateWarehouseStockVisibilityGrant extends CreateRecord
{
    protected static string $resource = WarehouseStockVisibilityGrantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(WarehouseStockVisibilityGrantService::class)->create($data, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }

            $this->dispatch('form-validation-error', livewireId: $this->getId());
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return WarehouseStockVisibilityGrantResource::getUrl('index');
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Shared warehouse visibility granted')
            ->body('The selected warehouse access scopes were added. Operational responsibility and stock ownership were not changed.');
    }
}
