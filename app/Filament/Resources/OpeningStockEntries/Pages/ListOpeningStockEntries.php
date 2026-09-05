<?php

namespace App\Filament\Resources\OpeningStockEntries\Pages;

use App\Filament\Resources\OpeningStockEntries\OpeningStockEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOpeningStockEntries extends ListRecords
{
    protected static string $resource = OpeningStockEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Post Opening Stock')];
    }
}
