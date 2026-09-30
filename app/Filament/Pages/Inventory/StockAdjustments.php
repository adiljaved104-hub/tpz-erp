<?php

namespace App\Filament\Pages\Inventory;

class StockAdjustments extends StockAdjustmentGridPage
{
    protected static ?string $slug = 'stock-adjustments';

    protected static ?string $navigationLabel = 'Stock Adjustments';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;
}
