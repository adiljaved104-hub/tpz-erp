<?php

namespace App\Filament\Resources\StockRequests;

use App\Enums\InventoryPermission;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Filament\Resources\StockRequests\Pages\ListStockRequests;
use App\Filament\Resources\StockRequests\Pages\ViewStockRequest;
use App\Filament\Resources\StockRequests\Schemas\StockRequestForm;
use App\Filament\Resources\StockRequests\Schemas\StockRequestInfolist;
use App\Filament\Resources\StockRequests\Tables\StockRequestsTable;
use App\Models\StockRequest;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\StockRequestService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockRequestResource extends Resource
{
    protected static ?string $model = StockRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock Requests';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(StockRequestService::class)->visibleQuery($user)
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewStockRequests);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::CreateStockRequests);
    }

    public static function canView($record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $record instanceof StockRequest && app(StockRequestService::class)->canView($user, $record);
    }

    public static function form(Schema $schema): Schema
    {
        return StockRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockRequests::route('/'),
            'create' => CreateStockRequest::route('/create'),
            'view' => ViewStockRequest::route('/{record}'),
        ];
    }
}
