<?php

namespace App\Filament\Resources\WebSalesOrders\Schemas;

use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WebSalesOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Web Sale')->schema([
                TextEntry::make('reference')->label('Order Ref'),
                TextEntry::make('web_sales_status')->label('Status')->state(fn (Order $record): string => $record->webSalesStatusLabel())->badge(),
                TextEntry::make('order_date')->date('d M Y'),
                TextEntry::make('handledBy.name')->label('Sales Employee'),
                TextEntry::make('customer_name')->label('Customer'),
                TextEntry::make('customer_phone')->label('WhatsApp / Phone'),
                TextEntry::make('web_sales_channel')->label('Channel')->badge(),
                TextEntry::make('warehouse.name')->label('Inventory Source'),
            ])->columns(4),
            Section::make('Delivery')->schema([
                TextEntry::make('delivery_type')->label('Delivery')->badge(),
                TextEntry::make('courier_name')->placeholder('—'),
                TextEntry::make('tracking_number')->label('Tracking / AWB')->placeholder('—'),
                TextEntry::make('delivered_at')->dateTime('d M Y, h:i A')->placeholder('Not delivered'),
            ])->columns(4),
            Section::make('Products')->schema([
                RepeatableEntry::make('items')->state(fn (Order $record) => $record->items()->with([
                    'upgradeSelection' => fn ($query) => $query->select(['id', 'order_item_id', 'configuration_snapshot']),
                ])->orderBy('line_number')->get())->schema([
                    TextEntry::make('line_number')->label('Line'), TextEntry::make('sku'), TextEntry::make('product_name')->label('Product'),
                    TextEntry::make('configuration')->state(fn (OrderItem $record): string => $record->upgradeSelection?->description() ?? 'Base Configuration'),
                    TextEntry::make('ordered_quantity')->label('Qty'), TextEntry::make('selling_price')->money('AED'),
                ])->columns(6),
            ]),
            Section::make('Financial Summary')->schema([
                TextEntry::make('grand_total')->label('Revenue')->money('AED')->visible(fn (): bool => self::allowed(WebSalesPermission::ViewRevenue)),
                TextEntry::make('cogs_total')->label('COGS')->money('AED')->state(fn (Order $record): string => self::cogs($record))->visible(fn (): bool => self::allowed(WebSalesPermission::ViewCost)),
                TextEntry::make('gross_profit')->label('Gross Profit')->money('AED')->state(fn (Order $record): string => bcsub((string) $record->grand_total, self::cogs($record), 2))->visible(fn (): bool => self::allowed(WebSalesPermission::ViewGrossProfit)),
            ])->columns(3)->visible(fn (): bool => self::allowed(WebSalesPermission::ViewRevenue) || self::allowed(WebSalesPermission::ViewCost) || self::allowed(WebSalesPermission::ViewGrossProfit)),
        ]);
    }

    private static function allowed(WebSalesPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, $permission);
    }

    private static function cogs(Order $record): string
    {
        return $record->fulfillment?->items()->with('upgradeExecution:id,order_fulfillment_item_id,final_configured_cogs')->get()
            ->reduce(fn (string $total, $item): string => bcadd($total, $item->effectiveCogsTotal(), 4), '0.0000') ?? '0.0000';
    }
}
