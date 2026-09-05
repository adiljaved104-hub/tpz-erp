<?php

namespace App\Filament\Resources\EmployeeWarnings\Pages;

use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Services\Hr\EmployeeWarningService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployeeWarning extends CreateRecord
{
    protected static string $resource = EmployeeWarningResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(EmployeeWarningService::class)->issue($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return EmployeeWarningResource::getUrl('view', ['record' => $this->record]);
    }
}
