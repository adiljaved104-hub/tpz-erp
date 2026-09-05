<?php

namespace App\Filament\Resources\WarrantyRepairs\Pages;

use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Filament\Resources\WarrantyRepairs\Widgets\WarrantyRepairStats;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarrantyRepairs extends ListRecords
{
    protected static string $resource = WarrantyRepairResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('responsibilitySetup')->label('Responsibility Setup')->icon('heroicon-o-user-group')->color('gray')
                ->url(fn (): string => ResponsibilityAssignmentResource::getUrl())
                ->visible(fn (): bool => ResponsibilityAssignmentResource::canViewAny()),
            CreateAction::make()->label('Receive Service Item'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [WarrantyRepairStats::class];
    }
}
