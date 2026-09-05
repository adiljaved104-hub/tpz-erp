<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Pages;

use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListResponsibilityAssignments extends ListRecords
{
    protected static string $resource = ResponsibilityAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
