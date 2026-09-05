<?php

namespace App\Filament\Resources\ProductBrands\Pages;

use App\Filament\Resources\ProductBrands\ProductBrandResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProductBrand extends ViewRecord
{
    protected static string $resource = ProductBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
