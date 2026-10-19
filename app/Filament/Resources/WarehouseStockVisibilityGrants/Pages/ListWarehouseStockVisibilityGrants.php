<?php

namespace App\Filament\Resources\WarehouseStockVisibilityGrants\Pages;

use App\Filament\Resources\WarehouseStockVisibilityGrants\WarehouseStockVisibilityGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouseStockVisibilityGrants extends ListRecords
{
    protected static string $resource = WarehouseStockVisibilityGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
