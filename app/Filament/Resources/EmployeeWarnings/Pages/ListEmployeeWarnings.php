<?php

namespace App\Filament\Resources\EmployeeWarnings\Pages;

use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeWarnings extends ListRecords
{
    protected static string $resource = EmployeeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Issue Warning')
                ->visible(fn (): bool => EmployeeWarningResource::canCreate()),
        ];
    }
}
