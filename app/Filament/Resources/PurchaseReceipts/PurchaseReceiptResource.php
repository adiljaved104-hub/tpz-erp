<?php

namespace App\Filament\Resources\PurchaseReceipts;

use App\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Filament\Resources\PurchaseReceipts\Schemas\PurchaseReceiptInfolist;
use App\Filament\Resources\PurchaseReceipts\Tables\PurchaseReceiptsTable;
use App\Models\PurchaseReceipt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PurchaseReceiptResource extends Resource
{
    protected static ?string $model = PurchaseReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Goods Received Notes';

    protected static ?string $modelLabel = 'Goods Receipt (GRN)';

    protected static ?string $pluralModelLabel = 'Goods Receipts (GRNs)';

    public static function infolist(Schema $schema): Schema
    {
        return PurchaseReceiptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseReceiptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseReceipts::route('/'), 'view' => ViewPurchaseReceipt::route('/{record}')];
    }
}
