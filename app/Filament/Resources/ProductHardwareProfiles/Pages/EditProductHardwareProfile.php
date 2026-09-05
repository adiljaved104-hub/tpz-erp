<?php

namespace App\Filament\Resources\ProductHardwareProfiles\Pages;

use App\Filament\Resources\ProductHardwareProfiles\ProductHardwareProfileResource;
use App\Models\ProductHardwareProfile;
use App\Services\Upgrades\HardwareProfileService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProductHardwareProfile extends EditRecord
{
    protected static string $resource = ProductHardwareProfileResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'slots' => $this->record->slots()->get()->toArray()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ProductHardwareProfile $record */
        return app(HardwareProfileService::class)->save($record->product, $data, auth()->user());
    }
}
