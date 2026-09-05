<?php

namespace App\Filament\Resources\OpeningStockEntries;

use App\Enums\InventoryPermission;
use App\Filament\Resources\OpeningStockEntries\Pages\CreateOpeningStockEntry;
use App\Filament\Resources\OpeningStockEntries\Pages\ListOpeningStockEntries;
use App\Filament\Resources\OpeningStockEntries\Pages\ViewOpeningStockEntry;
use App\Filament\Resources\OpeningStockEntries\Schemas\OpeningStockEntryForm;
use App\Filament\Resources\OpeningStockEntries\Schemas\OpeningStockEntryInfolist;
use App\Filament\Resources\OpeningStockEntries\Tables\OpeningStockEntriesTable;
use App\Models\OpeningStockEntry;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OpeningStockEntryResource extends Resource
{
    protected static ?string $model = OpeningStockEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Opening Stock';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->select([
            'id', 'reference', 'product_id', 'warehouse_id', 'available_quantity', 'damaged_quantity',
            'reason', 'movement_group', 'posted_by_user_id', 'posted_at', 'created_at',
        ])->with(['product:id,sku,name,status', 'warehouse:id,name,code,status', 'postedBy:id,name,email']);
        $user = auth()->user();

        if ($user instanceof User) {
            $query->whereExists(function ($inventories) use ($user): void {
                $inventories->selectRaw('1')->from('product_inventories as scoped_opening_inventory')
                    ->whereColumn('scoped_opening_inventory.product_id', 'opening_stock_entries.product_id')
                    ->whereColumn('scoped_opening_inventory.warehouse_id', 'opening_stock_entries.warehouse_id')
                    ->whereIn('scoped_opening_inventory.id', app(ResponsibilityProductScopeService::class)->inventoryIds($user));
            });
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials)) {
            $query->addSelect('unit_cost');
        }

        return $query;
    }

    public static function form(Schema $schema): Schema
    {
        return OpeningStockEntryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OpeningStockEntryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OpeningStockEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpeningStockEntries::route('/'),
            'create' => CreateOpeningStockEntry::route('/create'),
            'view' => ViewOpeningStockEntry::route('/{record}'),
        ];
    }
}
