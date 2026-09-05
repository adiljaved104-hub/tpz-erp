<?php

namespace App\Filament\Resources\StockTransfers\Schemas;

use App\Enums\StockTransferPermission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Authorization\StockTransferAuthorization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockTransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        $user = auth()->user();
        $cost = $user instanceof User && app(StockTransferAuthorization::class)->allows($user, StockTransferPermission::ViewCost, $record instanceof StockTransfer ? $record : null);
        $relation = $cost ? 'costedDisplayItems' : 'displayItems';
        $entries = [TextEntry::make('sku')->label('SKU'), TextEntry::make('product_name')->label('Product'), TextEntry::make('quantity')->label('Qty'), TextEntry::make('dispatched_quantity')->label('Dispatched'), TextEntry::make('received_quantity')->label('Received'), TextEntry::make('returned_quantity')->label('Returned')];
        $columns = collect(['SKU', 'Product', 'Qty', 'Dispatched', 'Received', 'Returned'])->map(fn (string $label) => TableColumn::make($label))->all();
        if ($cost) {
            $entries[] = TextEntry::make('dispatch_unit_cost')->label('Dispatch Cost')->money('AED', decimalPlaces: 2);
            $columns[] = TableColumn::make('Dispatch Cost');
        }

        return $schema->components([
            Section::make('Stock Transfer')->columns(2)->schema([
                TextEntry::make('reference'), TextEntry::make('status')->badge(), TextEntry::make('sourceWarehouse.name')->label('From'), TextEntry::make('destinationWarehouse.name')->label('To'),
                TextEntry::make('transfer_date')->date('d M Y'), TextEntry::make('handledBy.name')->label('Handled By')->placeholder('—'), TextEntry::make('dispatched_at')->dateTime('d M Y, h:i A')->placeholder('—'), TextEntry::make('received_at')->dateTime('d M Y, h:i A')->placeholder('—'), TextEntry::make('notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Products')->schema([RepeatableEntry::make($relation)->hiddenLabel()->schema($entries)->table($columns)->columnSpanFull()]),
            Section::make('Timeline')->collapsed()->schema([RepeatableEntry::make('statusEvents')->hiddenLabel()->schema([TextEntry::make('to_status')->label('Status')->badge(), TextEntry::make('actor.name')->label('By'), TextEntry::make('created_at')->dateTime('d M Y, h:i A'), TextEntry::make('reason')->placeholder('—')])->table([TableColumn::make('Status'), TableColumn::make('By'), TableColumn::make('When'), TableColumn::make('Reason')])]),
        ]);
    }
}
