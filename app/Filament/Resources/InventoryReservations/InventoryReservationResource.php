<?php

namespace App\Filament\Resources\InventoryReservations;

use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\InventoryReservations\Pages\ViewInventoryReservation;
use App\Filament\Resources\InventoryReservations\Schemas\InventoryReservationInfolist;
use App\Filament\Resources\InventoryReservations\Tables\InventoryReservationsTable;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryReservationResource extends Resource
{
    protected static ?string $model = InventoryReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Reservations';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? parent::getEloquentQuery()->whereIn('product_inventory_id', app(ResponsibilityProductScopeService::class)->inventoryIds($user))
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function infolist(Schema $schema): Schema
    {
        return InventoryReservationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoryReservationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryReservations::route('/'),
            'view' => ViewInventoryReservation::route('/{record}'),
        ];
    }
}
