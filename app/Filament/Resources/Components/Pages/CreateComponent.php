<?php

namespace App\Filament\Resources\Components\Pages;

use App\Filament\Resources\Components\ComponentResource;
use App\Services\Components\ComponentCatalogService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateComponent extends CreateRecord
{
    protected static string $resource = ComponentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ComponentCatalogService::class)->create($data, auth()->user());
    }
}
