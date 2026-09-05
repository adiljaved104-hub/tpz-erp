<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class WarehouseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('code')->badge(),
            TextEntry::make('location_type')->label('Location Type')->badge(),
            TextEntry::make('marketplacePlatform.name')->label('Marketplace Platform')->placeholder('-'),
            TextEntry::make('fulfillment_tag')->label('Fulfilment Tag')->placeholder('-'),
            IconEntry::make('status')->label('Active')->boolean(),
            IconEntry::make('is_default')->label('Default')->boolean(),
            TextEntry::make('address')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('updated_at')->dateTime(),
        ]);
    }
}
