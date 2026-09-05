<?php

namespace App\Filament\Resources\MarketplacePlatforms\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketplacePlatformInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Platform')->schema([
                TextEntry::make('name'),
                TextEntry::make('code'),
                TextEntry::make('return_handling_mode')->label('Return Handling Mode')->badge()->placeholder('Not configured'),
                TextEntry::make('defaultReturnReceivingWarehouse.name')->label('Default Return Receiving Location')->placeholder('Not configured'),
                IconEntry::make('customer_return_claims_enabled')->label('Claims Enabled')->boolean(),
                TextEntry::make('claim_program_name')->label('Claim Program')->placeholder('Not configured'),
                IconEntry::make('status')->label('Active')->boolean(),
                TextEntry::make('createdBy.name')->label('Created By')->placeholder('Migration/System'),
                TextEntry::make('created_at')->dateTime('d M Y, h:i A'),
            ])->columns(2),
        ]);
    }
}
