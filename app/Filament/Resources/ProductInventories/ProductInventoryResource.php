<?php

namespace App\Filament\Resources\ProductInventories;

use App\Enums\PurchasePermission;
use App\Filament\Resources\ProductInventories\Pages\ListProductInventories;
use App\Filament\Resources\ProductInventories\Pages\ViewProductInventory;
use App\Filament\Resources\ProductInventories\Schemas\ProductInventoryInfolist;
use App\Filament\Resources\ProductInventories\Tables\ProductInventoriesTable;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryReadService;
use App\Services\Purchases\PurchaseCostHistoryService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductInventoryResource extends Resource
{
    protected static ?string $model = ProductInventory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Location Balances';

    protected static ?string $modelLabel = 'Inventory Balance';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $query = app(InventoryReadService::class)->inventories($user);

        if (app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewCostHistory)) {
            app(PurchaseCostHistoryService::class)->addLatestCostSelect($query, 'product_inventories.product_id', $user);
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInventoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductInventoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductInventories::route('/'),
            'view' => ViewProductInventory::route('/{record}'),
        ];
    }
}
