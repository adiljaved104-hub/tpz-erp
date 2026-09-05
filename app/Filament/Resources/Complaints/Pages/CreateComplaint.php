<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Exceptions\ComplaintException;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Services\ServiceCases\ComplaintService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateComplaint extends CreateRecord
{
    protected static string $resource = ComplaintResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ComplaintService::class)->create($data + ['idempotency_key' => (string) Str::uuid()], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("data.{$field}", $message);
                }
            }
            $this->dispatch('form-validation-error', livewireId: $this->getId());
            throw (new Halt)->rollBackDatabaseTransaction();
        } catch (ComplaintException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Complaint could not be created')->body($exception->getMessage() ?: 'You are not authorized to create this Complaint.')->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getRedirectUrl(): string
    {
        return ComplaintResource::getUrl('view', ['record' => $this->record]);
    }
}
