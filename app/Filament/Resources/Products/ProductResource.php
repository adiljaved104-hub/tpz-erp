<?php

namespace App\Filament\Resources\Products;

use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Schemas\ProductInfolist;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseCostHistoryService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->products();
        $user = auth()->user();
        $fields = [
            'id', 'sku', 'name', 'brand', 'brand_id', 'category', 'category_id', 'model', 'condition', 'processor', 'ram', 'storage',
            'screen_size', 'graphics', 'color', 'warranty', 'description', 'status', 'created_at', 'updated_at',
        ];

        if ($user instanceof User && app(ProductAuthorization::class)->allows($user, ProductPermission::ViewSellingPrice)) {
            $fields[] = 'selling_price';
        }

        if ($user instanceof User && app(ProductAuthorization::class)->allows($user, ProductPermission::ViewCostPrice)) {
            $fields[] = 'cost_price';
        }

        $query->select($fields)->with(['brandRelation:id,name,status', 'categoryRelation:id,name,status']);

        if ($user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewCostHistory)) {
            app(PurchaseCostHistoryService::class)->addLatestCostSelect($query, 'products.id', $user);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
