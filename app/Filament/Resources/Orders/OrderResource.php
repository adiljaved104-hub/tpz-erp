<?php

namespace App\Filament\Resources\Orders;

use App\Enums\CustomerReturnPermission;
use App\Enums\SafetClaimStatus;
use App\Filament\Resources\Orders\Pages\CreateOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Orders\OrderReadService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Orders';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $query = app(OrderReadService::class)->orders($user)
            ->with(['platform:id,name,code', 'warehouse:id,name,code', 'handledBy:id,name'])
            ->withCount(['customerReturns as returns_count', 'warrantyRepairs as warranty_cases_count'])
            ->withExists(['customerReturns as is_refunded' => fn ($returns) => $returns->whereHas('refund')]);

        if (app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::ViewRefundAmount)) {
            $query->addSelect(['claim_recovery' => SafetClaim::query()
                ->selectRaw('COALESCE(SUM(reimbursed_amount), 0)')
                ->whereColumn('safet_claims.order_id', 'orders.id')
                ->whereNotNull('paid_at')->whereNotNull('reimbursed_amount')
                ->whereIn('status', [SafetClaimStatus::Paid->value, SafetClaimStatus::Closed->value])]);
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
