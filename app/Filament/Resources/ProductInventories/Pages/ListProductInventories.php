<?php

namespace App\Filament\Resources\ProductInventories\Pages;

use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use Filament\Resources\Pages\ListRecords;

class ListProductInventories extends ListRecords
{
    protected static string $resource = ProductInventoryResource::class;
}
