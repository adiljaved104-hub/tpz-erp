<?php

namespace App\Filament\Resources\MarketplacePlatforms\Pages;

use App\Filament\Resources\MarketplacePlatforms\MarketplacePlatformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketplacePlatforms extends ListRecords
{
    protected static string $resource = MarketplacePlatformResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
