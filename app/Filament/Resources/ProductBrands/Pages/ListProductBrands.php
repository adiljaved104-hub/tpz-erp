<?php

namespace App\Filament\Resources\ProductBrands\Pages;

use App\Filament\Resources\ProductBrands\ProductBrandResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductBrands extends ListRecords
{
    protected static string $resource = ProductBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
