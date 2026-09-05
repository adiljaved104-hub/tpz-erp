<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Actions\Catalog\CreateProductCategory as CreateProductCategoryAction;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductCategory extends CreateRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateProductCategoryAction::class)->handle(new CreateCatalogItemData($data['name']), auth()->user());
    }
}
