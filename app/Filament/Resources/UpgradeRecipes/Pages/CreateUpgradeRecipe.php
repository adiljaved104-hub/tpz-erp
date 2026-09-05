<?php

namespace App\Filament\Resources\UpgradeRecipes\Pages;

use App\Filament\Resources\UpgradeRecipes\UpgradeRecipeResource;
use App\Models\SalesConfiguration;
use App\Services\Upgrades\UpgradeRecipeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUpgradeRecipe extends CreateRecord
{
    protected static string $resource = UpgradeRecipeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['sales_configuration_id'] ??= request()->integer('configuration') ?: null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(UpgradeRecipeService::class)->create(SalesConfiguration::query()->findOrFail($data['sales_configuration_id']), $data, auth()->user());
    }
}
