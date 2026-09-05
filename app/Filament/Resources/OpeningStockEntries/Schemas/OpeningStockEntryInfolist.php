<?php

namespace App\Filament\Resources\OpeningStockEntries\Schemas;

use App\Enums\InventoryPermission;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OpeningStockEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Opening Stock')->schema([
                TextEntry::make('reference'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('product.sku')->label('SKU'),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('available_quantity')->label('Available'),
                TextEntry::make('damaged_quantity')->label('Damaged'),
                ...(self::canViewFinancials() ? [TextEntry::make('unit_cost')->money('AED', decimalPlaces: 2)] : []),
                TextEntry::make('postedBy.name')->label('Posted By'),
                TextEntry::make('posted_at')->dateTime('d M Y, h:i A'),
                TextEntry::make('reason')->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
