<?php

namespace App\Filament\Resources\OpeningStockEntries\Tables;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OpeningStockEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('product.name')->label('Product')->searchable(),
            TextColumn::make('product.sku')->label('SKU'),
            TextColumn::make('warehouse.name')->label('Warehouse'),
            TextColumn::make('available_quantity')->label('Available'),
            TextColumn::make('damaged_quantity')->label('Damaged'),
            ...(self::canViewFinancials() ? [TextColumn::make('unit_cost')->money('AED', decimalPlaces: 2)] : []),
            TextColumn::make('postedBy.name')->label('Posted By'),
            TextColumn::make('posted_at')->dateTime('d M Y, h:i A')->sortable(),
        ])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
