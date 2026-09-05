<?php

namespace App\Filament\Resources\UpgradeRecipes\Pages;

use App\Filament\Resources\UpgradeRecipes\UpgradeRecipeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUpgradeRecipes extends ListRecords
{
    protected static string $resource = UpgradeRecipeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->url(UpgradeRecipeResource::getUrl('create', ['configuration' => request()->integer('configuration')]))];
    }
}
