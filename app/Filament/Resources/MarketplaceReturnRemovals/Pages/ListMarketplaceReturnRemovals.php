<?php

namespace App\Filament\Resources\MarketplaceReturnRemovals\Pages;

use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMarketplaceReturnRemovals extends ListRecords
{
    protected static string $resource = MarketplaceReturnRemovalResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Marketplace Removal')];
    }
}
