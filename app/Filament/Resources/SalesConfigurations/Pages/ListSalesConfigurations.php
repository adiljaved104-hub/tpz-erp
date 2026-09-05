<?php

namespace App\Filament\Resources\SalesConfigurations\Pages;

use App\Filament\Resources\SalesConfigurations\SalesConfigurationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalesConfigurations extends ListRecords
{
    protected static string $resource = SalesConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
