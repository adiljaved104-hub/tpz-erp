<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Products\CreateProduct as CreateProductAction;
use App\DTOs\Products\CreateProductData;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateProductAction::class)->handle(new CreateProductData(
            name: $data['name'],
            brandId: (int) $data['brand_id'],
            categoryId: (int) $data['category_id'],
            condition: $data['condition'],
            model: $data['model'] ?? null,
            processor: $data['processor'] ?? null,
            ram: $data['ram'] ?? null,
            storage: $data['storage'] ?? null,
            screenSize: $data['screen_size'] ?? null,
            graphics: $data['graphics'] ?? null,
            color: $data['color'] ?? null,
            warranty: $data['warranty'],
            costPrice: $data['cost_price'] ?? null,
            costPriceProvided: array_key_exists('cost_price', $data),
            sellingPrice: $data['selling_price'] ?? null,
            sellingPriceProvided: array_key_exists('selling_price', $data),
            description: $data['description'] ?? null,
            duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
        ), auth()->user());
    }
}
