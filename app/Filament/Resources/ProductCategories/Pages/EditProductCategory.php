<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Actions\Catalog\RenameProductCategory;
use App\DTOs\Catalog\RenameCatalogItemData;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Models\ProductCategory;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductCategory extends EditRecord
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    { /** @var ProductCategory $record */ return app(RenameProductCategory::class)->handle($record, new RenameCatalogItemData($data['name']), auth()->user());
    }
}
