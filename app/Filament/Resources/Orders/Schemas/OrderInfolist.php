<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CustomerReturnPermission;
use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $selling = self::allowed(OrderPermission::ViewSellingPrice, $schema->getRecord());
        $refundFinancials = auth()->user() instanceof User && app(CustomerReturnAuthorization::class)
            ->allows(auth()->user(), CustomerReturnPermission::ViewRefundAmount);
        $entries = [
            TextEntry::make('line_number')->label('Line'),
            TextEntry::make('sku')->label('SKU'),
            TextEntry::make('product_name')->label('Product'),
            TextEntry::make('configuration_name')->label('Configuration')->state(fn (OrderItem $record): string => $record->upgradeSelection?->description() ?? 'Base Configuration'),
            TextEntry::make('ordered_quantity')->label('Qty'),
        ];
        $columns = [TableColumn::make('Line'), TableColumn::make('SKU'), TableColumn::make('Product'), TableColumn::make('Configuration'), TableColumn::make('Qty')];

        if ($selling) {
            $entries[] = TextEntry::make('selling_price')->label('Selling Price')->money('AED', decimalPlaces: 2);
            $entries[] = TextEntry::make('line_total')->label('Line Total')->money('AED', decimalPlaces: 2);
            $columns[] = TableColumn::make('Selling Price');
            $columns[] = TableColumn::make('Line Total');
        }

        return $schema->components([
            Section::make('Order')->schema([
                TextEntry::make('reference')->label('Order Reference'),
                TextEntry::make('status')->badge(),
                TextEntry::make('platform.name')->label('Platform')->placeholder('Manual / No Platform'),
                TextEntry::make('external_order_number')->label('External Order Number')->placeholder('Not provided'),
                TextEntry::make('order_date')->date('d M Y'),
                TextEntry::make('warehouse.name')->label('Fulfilled From'),
                TextEntry::make('handledBy.name')->label('Handled By'),
                TextEntry::make('fulfillment.reference')->label('Shipment Reference')->visible(fn (?Order $record): bool => $record?->status?->value === 'fulfilled'),
                TextEntry::make('fulfillment.fulfilled_at')->label('Shipped At')->dateTime('d M Y, h:i A')->visible(fn (?Order $record): bool => $record?->status?->value === 'fulfilled'),
                TextEntry::make('notes')->placeholder('No notes')->columnSpanFull(),
            ])->columns(2),
            Section::make('Products')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->state(fn (Order $record): Collection => self::items($record, $selling))->schema($entries)->table($columns)->columnSpanFull(),
            ]),
            Section::make('Returns / Service')->columns(4)->schema([
                TextEntry::make('returns_count')->label('Has Return')->formatStateUsing(fn ($state): string => (int) $state > 0 ? 'Yes' : 'No')->badge(),
                TextEntry::make('is_refunded')->label('Refunded')->formatStateUsing(fn ($state): string => (bool) $state ? 'Yes' : 'No')->badge(),
                TextEntry::make('warranty_cases_count')->label('Warranty Cases'),
                ...($refundFinancials ? [TextEntry::make('claim_recovery')->label('Claim Recovery')->money('AED', decimalPlaces: 2)] : []),
            ]),
            Section::make('Timeline')->schema([
                RepeatableEntry::make('statusEvents')->hiddenLabel()->schema([
                    TextEntry::make('to_status')->label('Status')->badge(),
                    TextEntry::make('actor.name')->label('By'),
                    TextEntry::make('created_at')->label('When')->dateTime('d M Y, h:i A'),
                    TextEntry::make('reason')->placeholder('—'),
                ])->table([
                    TableColumn::make('Status'), TableColumn::make('By'), TableColumn::make('When'), TableColumn::make('Reason'),
                ])->columnSpanFull(),
            ])->collapsed(),
        ]);
    }

    private static function allowed(OrderPermission $permission, mixed $order): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, $permission, $order instanceof Order ? $order : null);
    }

    private static function items(Order $order, bool $selling): Collection
    {
        $fields = ['id', 'order_id', 'line_number', 'sku', 'product_name', 'ordered_quantity'];

        if ($selling) {
            array_push($fields, 'selling_price', 'line_total');
        }

        return $order->items()->select($fields)->with([
            'upgradeSelection' => fn ($query) => $query->select(['id', 'order_item_id', 'configuration_snapshot']),
        ])->orderBy('line_number')->get();
    }
}
