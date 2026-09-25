<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Stock Request')->columns(2)->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('purpose'),
                TextEntry::make('order.reference')->label('Order')->placeholder('—'),
                TextEntry::make('requester.name')->label('Requested By'),
                TextEntry::make('created_at')->label('Requested At')->dateTime('d M Y, h:i A'),
                TextEntry::make('reason')->columnSpanFull(),
            ]),
            Section::make('Requested Products')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->schema([
                    TextEntry::make('sku')->label('SKU'),
                    TextEntry::make('product_name')->label('Product'),
                    TextEntry::make('warehouse_name')->label('Warehouse'),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('proposed_source_summary')->label('Proposed Employee/Team Sources')->placeholder('None available'),
                    TextEntry::make('system_unassigned_quantity')->label('System / Unassigned'),
                ])->table([
                    TableColumn::make('SKU'), TableColumn::make('Product'), TableColumn::make('Warehouse'),
                    TableColumn::make('Qty'), TableColumn::make('Proposed Employee/Team Sources'), TableColumn::make('System / Unassigned'),
                ])->columnSpanFull(),
            ]),
        ]);
    }
}
