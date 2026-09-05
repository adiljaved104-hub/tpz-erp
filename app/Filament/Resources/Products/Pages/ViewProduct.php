<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\UpgradePermission;
use App\Filament\Resources\ProductHardwareProfiles\ProductHardwareProfileResource;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Authorization\UpgradeAuthorization;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
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
            EditAction::make(),
        ];
    }
}
