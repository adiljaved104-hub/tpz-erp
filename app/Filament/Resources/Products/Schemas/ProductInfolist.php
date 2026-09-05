<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sku')
                    ->label('SKU'),
                TextEntry::make('name'),
                TextEntry::make('brandRelation.name')->label('Brand')->state(fn (Product $record): string => $record->displayBrandName()),
                TextEntry::make('categoryRelation.name')->label('Category')->state(fn (Product $record): string => $record->displayCategoryName()),
                TextEntry::make('model')
                    ->placeholder('-'),
                TextEntry::make('condition')
                    ->badge()
                    ->formatStateUsing(fn (ProductCondition $state): string => $state->label()),
                TextEntry::make('processor')
                    ->placeholder('-'),
                TextEntry::make('ram')
                    ->placeholder('-'),
                TextEntry::make('storage')
                    ->placeholder('-'),
                TextEntry::make('screen_size')
                    ->placeholder('-'),
                TextEntry::make('graphics')
                    ->placeholder('-'),
                TextEntry::make('color')
                    ->placeholder('-'),
                TextEntry::make('warranty')
                    ->formatStateUsing(fn (int $state): string => Product::warrantyLabel($state)),
                TextEntry::make('cost_price')
                    ->label('Cost Price')
                    ->money('AED', decimalPlaces: 2)
                    ->visible(fn (Product $record): bool => self::allowed(ProductPermission::ViewCostPrice, $record)),
                TextEntry::make('selling_price')
                    ->money('AED')
                    ->visible(fn (Product $record): bool => self::allowed(ProductPermission::ViewSellingPrice, $record)),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color()),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    private static function allowed(ProductPermission $permission, Product $product): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ProductAuthorization::class)->allows($user, $permission, $product);
    }
}
