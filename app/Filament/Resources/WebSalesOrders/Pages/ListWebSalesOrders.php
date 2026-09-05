<?php

namespace App\Filament\Resources\WebSalesOrders\Pages;

use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWebSalesOrders extends ListRecords
{
    protected static string $resource = WebSalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Web Sale')];
    }
}
