<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use App\Models\StockRequestSourceLine;
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
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('sku')->label('SKU'),
                    TextEntry::make('product_name')->label('Product'),
                    TextEntry::make('warehouse_name')->label('Warehouse'),
                    TextEntry::make('quantity')->label('Requested Qty'),
                    RepeatableEntry::make('sourceLines')->label('Available Sources / Approval Status')->schema([
                        TextEntry::make('source_label')->label('Source'),
                        TextEntry::make('proposed_quantity')->label('Proposed Qty'),
                        TextEntry::make('approval_route')->label('Approval Route')
                            ->state(fn (StockRequestSourceLine $record): string => $record->requiresOwnerAdminApproval() ? 'Owner/Admin' : 'Allocation Holder'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('decidedBy.employee.name')->label('Decided By')->placeholder('—'),
                        TextEntry::make('decided_at')->label('Decided At')->dateTime('d M Y, h:i A')->placeholder('—'),
                        TextEntry::make('decision_note')->label('Note / Reason')->placeholder('—'),
                    ])->table([
                        TableColumn::make('Source'),
                        TableColumn::make('Proposed Qty'),
                        TableColumn::make('Approval Route'),
                        TableColumn::make('Status'),
                        TableColumn::make('Decided By'),
                        TableColumn::make('Decided At'),
                        TableColumn::make('Note / Reason'),
                    ])->columnSpanFull(),
                ])->columnSpanFull(),
            ]),
        ]);
    }
}
