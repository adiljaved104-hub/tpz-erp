<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\UpdateProductData;
use App\Enums\UpgradePermission;
use App\Filament\Resources\ProductHardwareProfiles\ProductHardwareProfileResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Authorization\UpgradeAuthorization;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('hardwareProfile')->label('Hardware Profile')->icon('heroicon-o-cpu-chip')
                ->visible(fn (): bool => app(UpgradeAuthorization::class)->allows(auth()->user(), UpgradePermission::ManageHardwareProfiles))
                ->url(fn (): string => $this->record->hardwareProfile
                    ? ProductHardwareProfileResource::getUrl('edit', ['record' => $this->record->hardwareProfile])
                    : ProductHardwareProfileResource::getUrl('create', ['product' => $this->record->id])),
            ViewAction::make(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        return app(UpdateProduct::class)->handle($record, new UpdateProductData(
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
