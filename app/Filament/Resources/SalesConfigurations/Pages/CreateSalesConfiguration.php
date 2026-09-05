<?php

namespace App\Filament\Resources\SalesConfigurations\Pages;

use App\Filament\Resources\SalesConfigurations\SalesConfigurationResource;
use App\Services\Upgrades\SalesConfigurationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSalesConfiguration extends CreateRecord
{
    protected static string $resource = SalesConfigurationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SalesConfigurationService::class)->create($data, auth()->user());
    }
}
