<?php

namespace App\Filament\Resources\ProductBrands\Pages;

use App\Actions\Catalog\CreateProductBrand as CreateProductBrandAction;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\Filament\Resources\ProductBrands\ProductBrandResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductBrand extends CreateRecord
{
    protected static string $resource = ProductBrandResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateProductBrandAction::class)->handle(new CreateCatalogItemData($data['name']), auth()->user());
    }
}
