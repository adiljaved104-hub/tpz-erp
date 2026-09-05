<?php

namespace App\Filament\Resources\SalesConfigurations\Pages;

use App\Filament\Resources\SalesConfigurations\SalesConfigurationResource;
use App\Services\Upgrades\SalesConfigurationService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSalesConfiguration extends EditRecord
{
    protected static string $resource = SalesConfigurationResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SalesConfigurationService::class)->update($record, $data, auth()->user());
    }
}
