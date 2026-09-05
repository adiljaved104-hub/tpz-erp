<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SupplierInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'),
            TextEntry::make('contact_person')->label('Contact Person')->placeholder('-'),
            TextEntry::make('phone')->placeholder('-'),
            TextEntry::make('email')->placeholder('-'),
            TextEntry::make('vat_number')->label('VAT Number')->placeholder('-'),
            IconEntry::make('status')->label('Active')->boolean(),
            TextEntry::make('address')->placeholder('-')->columnSpanFull(),
            TextEntry::make('notes')->placeholder('-')->columnSpanFull(),
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('updated_at')->dateTime(),
        ]);
    }
}
