<?php

namespace App\Filament\Resources\QcInspections\Pages;

use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Filament\Resources\QcInspections\Widgets\QcProgress;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQcInspections extends ListRecords
{
    protected static string $resource = QcInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Start QC')];
    }

    protected function getHeaderWidgets(): array
    {
        return [QcProgress::class];
    }
}
