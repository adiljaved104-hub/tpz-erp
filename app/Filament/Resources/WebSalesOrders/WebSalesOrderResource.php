<?php

namespace App\Filament\Resources\WebSalesOrders;

use App\Enums\WebSalesPermission;
use App\Filament\Resources\WebSalesOrders\Pages\CreateWebSalesOrder;
use App\Filament\Resources\WebSalesOrders\Pages\ListWebSalesOrders;
use App\Filament\Resources\WebSalesOrders\Pages\ViewWebSalesOrder;
use App\Filament\Resources\WebSalesOrders\Schemas\WebSalesOrderForm;
use App\Filament\Resources\WebSalesOrders\Schemas\WebSalesOrderInfolist;
use App\Filament\Resources\WebSalesOrders\Tables\WebSalesOrdersTable;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\WebSalesReadService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WebSalesOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Web Sales';

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $modelLabel = 'Web Sale';

    protected static ?string $pluralModelLabel = 'Web Sales Orders';

    protected static ?string $slug = 'web-sales/orders';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return self::allowed(WebSalesPermission::View);
    }

    public static function canCreate(): bool
    {
        return self::allowed(WebSalesPermission::Create);
    }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $record instanceof Order
            && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View, $record)
            && app(WebSalesReadService::class)->canAccess($user, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(WebSalesReadService::class)->orders($user)
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return WebSalesOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WebSalesOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebSalesOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebSalesOrders::route('/'),
            'create' => CreateWebSalesOrder::route('/create'),
            'view' => ViewWebSalesOrder::route('/{record}'),
        ];
    }

    private static function allowed(WebSalesPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, $permission);
    }
}
