<?php

namespace App\Filament\Resources\InventoryReservations\Schemas;

use App\Services\UserTimezoneResolver;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InventoryReservationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reservation')->schema([
                TextEntry::make('reference'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('product.sku')->label('SKU'),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('quantity'),
                TextEntry::make('status')->badge(),
                TextEntry::make('reason')->columnSpanFull(),
                TextEntry::make('reserved_at')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state, 'd M Y, h:i A')),
                TextEntry::make('released_at')->formatStateUsing(fn ($state): ?string => app(UserTimezoneResolver::class)->format($state, 'd M Y, h:i A'))->placeholder('—'),
            ])->columns(3),
        ]);
    }
}
