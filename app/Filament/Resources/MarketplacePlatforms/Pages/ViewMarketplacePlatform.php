<?php

namespace App\Filament\Resources\MarketplacePlatforms\Pages;

use App\Filament\Resources\MarketplacePlatforms\Actions\ChangeMarketplacePlatformCodeAction;
use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMarketplacePlatform extends ViewRecord
{
    protected static string $resource = MarketplacePlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [ChangeMarketplacePlatformCodeAction::make(), EditAction::make()];
    }
}
