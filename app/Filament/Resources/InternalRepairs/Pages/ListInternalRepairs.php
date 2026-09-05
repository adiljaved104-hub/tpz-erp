<?php

namespace App\Filament\Resources\InternalRepairs\Pages;

use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\InternalRepairs\Widgets\InternalRepairStats;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListInternalRepairs extends ListRecords
{
    protected static string $resource = InternalRepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('responsibilitySetup')->label('Responsibility Setup')->icon('heroicon-o-user-group')->color('gray')
                ->url(fn (): string => ResponsibilityAssignmentResource::getUrl())
                ->visible(fn (): bool => ResponsibilityAssignmentResource::canViewAny()),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [InternalRepairStats::class];
    }
}
