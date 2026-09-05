<?php

namespace App\Filament\Resources\ProductBrands\Pages;

use App\Actions\Catalog\RenameProductBrand;
use App\DTOs\Catalog\RenameCatalogItemData;
use App\Filament\Resources\ProductBrands\ProductBrandResource;
use App\Models\ProductBrand;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductBrand extends EditRecord
{
    protected static string $resource = ProductBrandResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    { /** @var ProductBrand $record */ return app(RenameProductBrand::class)->handle($record, new RenameCatalogItemData($data['name']), auth()->user());
    }
}
