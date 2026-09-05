<?php

namespace App\Filament\Resources\Complaints\Pages;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Complaints\Widgets\ComplaintStats;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComplaints extends ListRecords
{
    protected static string $resource = ComplaintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('responsibilitySetup')->label('Responsibility Setup')->icon('heroicon-o-user-group')->color('gray')
                ->url(fn (): string => ResponsibilityAssignmentResource::getUrl())
                ->visible(fn (): bool => ResponsibilityAssignmentResource::canViewAny()),
            CreateAction::make()->label('New Complaint'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ComplaintStats::class];
    }
}
