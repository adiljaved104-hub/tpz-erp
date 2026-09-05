<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Widgets\PendingPurchaseReceivingStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Purchase')];
    }

    protected function getHeaderWidgets(): array
    {
        return [PendingPurchaseReceivingStats::class];
    }
}
