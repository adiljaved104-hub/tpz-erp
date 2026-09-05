<?php

namespace App\Filament\Resources\ProductHardwareProfiles\Pages;

use App\Filament\Resources\ProductHardwareProfiles\ProductHardwareProfileResource;
use App\Models\Product;
use App\Services\Upgrades\HardwareProfileService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProductHardwareProfile extends CreateRecord
{
    protected static string $resource = ProductHardwareProfileResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['product_id'] ??= request()->integer('product') ?: null;

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(HardwareProfileService::class)->save(Product::query()->findOrFail($data['product_id']), $data, auth()->user());
    }
}
