<?php

namespace App\Filament\Resources\ProductCategories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductCategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name'), IconEntry::make('status')->label('Active')->boolean(),
            TextEntry::make('products_count')->label('Products')->state(fn ($record): int => $record->products()->count()),
            TextEntry::make('createdBy.name')->label('Created By')->placeholder('Migration'),
            TextEntry::make('created_at')->dateTime(), TextEntry::make('updated_at')->dateTime(),
        ]);
    }
}
