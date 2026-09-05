<?php

namespace App\Filament\Resources\WarningCategories\Pages;

use App\Filament\Resources\WarningCategories\WarningCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarningCategories extends ListRecords
{
    protected static string $resource = WarningCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => WarningCategoryResource::canCreate())];
    }
}
