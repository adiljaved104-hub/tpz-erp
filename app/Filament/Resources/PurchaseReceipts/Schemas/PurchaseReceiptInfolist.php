<?php

namespace App\Filament\Resources\PurchaseReceipts\Schemas;

use App\Enums\PurchasePermission;
use App\Models\PurchaseReceipt;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class PurchaseReceiptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        $financial = self::financial($record instanceof PurchaseReceipt ? $record : null);

        $itemEntries = [
            TextEntry::make('product.name')->label('Product'),
            TextEntry::make('accepted_quantity')->label('Accepted Qty'),
            TextEntry::make('damaged_quantity')->label('Damaged Qty'),
            TextEntry::make('rejected_quantity')->label('Rejected Qty'),
        ];
        $itemColumns = [
            TableColumn::make('Product'),
            TableColumn::make('Accepted Qty'),
            TableColumn::make('Damaged Qty'),
            TableColumn::make('Rejected Qty'),
        ];

        if ($financial) {
            $itemEntries[] = TextEntry::make('inventory_unit_cost')
                ->label('Inventory Unit Cost')
                ->money('AED', decimalPlaces: 2)
                ->extraEntryWrapperAttributes(['style' => 'white-space:nowrap;']);
            $itemColumns[] = TableColumn::make('Inventory Unit Cost');
        }

        return $schema->components([
            Section::make('Goods Received Note')->schema([
                TextEntry::make('reference')->label('GRN Reference'),
                TextEntry::make('purchase.reference')->label('Purchase Reference'),
                TextEntry::make('purchase.supplier.name')->label('Supplier')->placeholder('No Supplier'),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('supplier_delivery_note')->label('Supplier Delivery Note'),
                TextEntry::make('received_at')->label('Received At')->dateTime('d M Y, h:i A', config('app.timezone')),
                TextEntry::make('receivedBy.name')->label('Received By'),
                RepeatableEntry::make('receipt_items')
                    ->label('Receipt Items')
                    ->state(fn (PurchaseReceipt $record): Collection => self::receiptItems($record, $financial))
                    ->schema($itemEntries)
                    ->table($itemColumns)
                    ->extraAttributes(['style' => 'overflow-x:auto;'])
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    private static function receiptItems(PurchaseReceipt $receipt, bool $financial): Collection
    {
        $columns = [
            'id',
            'purchase_receipt_id',
            'product_id',
            'accepted_quantity',
            'damaged_quantity',
            'rejected_quantity',
        ];

        if ($financial) {
            $columns[] = 'inventory_unit_cost';
        }

        return $receipt->items()
            ->select($columns)
            ->with('product:id,name')
            ->get();
    }

    private static function financial(?PurchaseReceipt $receipt): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewFinancials, $receipt?->purchase);
    }
}
