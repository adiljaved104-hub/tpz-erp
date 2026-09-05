<?php

namespace App\Filament\Resources\ProductBrands;

use App\Filament\Resources\ProductBrands\Pages\CreateProductBrand;
use App\Filament\Resources\ProductBrands\Pages\EditProductBrand;
use App\Filament\Resources\ProductBrands\Pages\ListProductBrands;
use App\Filament\Resources\ProductBrands\Pages\ViewProductBrand;
use App\Filament\Resources\ProductBrands\Schemas\ProductBrandForm;
use App\Filament\Resources\ProductBrands\Schemas\ProductBrandInfolist;
use App\Filament\Resources\ProductBrands\Tables\ProductBrandsTable;
use App\Models\ProductBrand;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductBrandResource extends Resource
{
    protected static ?string $model = ProductBrand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Brands';

    protected static ?string $modelLabel = 'Brand';

    protected static ?string $pluralModelLabel = 'Brands';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ProductBrandForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductBrandInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductBrandsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListProductBrands::route('/'), 'create' => CreateProductBrand::route('/create'), 'view' => ViewProductBrand::route('/{record}'), 'edit' => EditProductBrand::route('/{record}/edit')];
    }
}
