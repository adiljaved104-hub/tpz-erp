<?php

namespace App\Filament\Resources\CustomerReturns;

use App\Enums\CustomerReturnPermission;
use App\Filament\Resources\CustomerReturns\Pages\CreateCustomerReturn;
use App\Filament\Resources\CustomerReturns\Pages\ListCustomerReturns;
use App\Filament\Resources\CustomerReturns\Pages\ViewCustomerReturn;
use App\Filament\Resources\CustomerReturns\Schemas\CustomerReturnForm;
use App\Filament\Resources\CustomerReturns\Schemas\CustomerReturnInfolist;
use App\Filament\Resources\CustomerReturns\Tables\CustomerReturnsTable;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\CustomerReturnReadService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerReturnResource extends Resource
{
    protected static ?string $model = CustomerReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Returns';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1=0');
        }

        $financial = app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::ViewRefundAmount);
        $refundFields = ['id', 'customer_return_id', 'warranty_repair_id', 'return_type', 'status', 'refund_date'];
        $claimFields = ['id', 'customer_return_id', 'reference', 'status'];
        if ($financial) {
            array_push($refundFields, 'refund_amount', 'currency');
            array_push($claimFields, 'reimbursed_amount', 'paid_at');
        }

        return app(CustomerReturnReadService::class)->query($user)->with([
            'order:id,reference,warehouse_id,marketplace_platform_id', 'platform:id,name', 'fulfillmentWarehouse:id,name', 'receivingWarehouse:id,name',
            'displayItems', 'refund' => fn ($query) => $query->select($refundFields),
            'refund.warrantyRepair:id,reference', 'claims' => fn ($query) => $query->select($claimFields),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerReturnForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerReturnInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomerReturnsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomerReturns::route('/'), 'create' => CreateCustomerReturn::route('/create'), 'view' => ViewCustomerReturn::route('/{record}')];
    }
}
