<?php

namespace App\Filament\Resources\CustomerReturns\Schemas;

use App\Enums\CustomerReturnReason;
use App\Enums\InventoryLocationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\OrderReadService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CustomerReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Return')->columns(2)->schema([
                Select::make('order_id')->label('Fulfilled Order')->options(fn () => self::fulfilledOrderOptions())
                    ->default(fn (): ?int => request()->integer('order_id') ?: null)->searchable()->required()->live()
                    ->afterStateUpdated(function ($state, Set $set): void {
                        $marketplace = self::isMarketplaceOrder($state);
                        $set('receiving_warehouse_id', $marketplace ? null : Warehouse::query()->where('is_default', true)->value('id'));
                        $itemId = self::singleFulfillmentItemId($state);
                        $set('items', $itemId === null ? [['quantity' => 1]] : [[
                            'order_fulfillment_item_id' => $itemId,
                            'quantity' => 1,
                        ]]);
                    }),
                Text::make(fn (Get $get): string => 'Marketplace Return Location: '.(self::fulfillmentLocationName($get('order_id')) ?? 'Select a fulfilled Order'))
                    ->visible(fn (Get $get): bool => self::isMarketplaceOrder($get('order_id'))),
                Select::make('receiving_warehouse_id')->label('Returned To')->options(fn () => Warehouse::query()->active()->whereNotIn('location_type', [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment])->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all())->default(fn () => Warehouse::query()->where('is_default', true)->value('id'))->required(fn (Get $get): bool => ! self::isMarketplaceOrder($get('order_id')))->visible(fn (Get $get): bool => ! self::isMarketplaceOrder($get('order_id')))->searchable(),
                Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Returned Items')->schema([
                Repeater::make('items')->minItems(1)->defaultItems(1)->schema([
                    Select::make('order_fulfillment_item_id')->label('Product')->options(function ($get): array {
                        $orderId = $get('../../order_id');
                        if (! $orderId) {
                            return [];
                        }

                        if (! self::authorizedOrderQuery()->whereKey((int) $orderId)->exists()) {
                            return [];
                        }

                        return OrderFulfillmentItem::query()->whereHas('fulfillment', fn ($q) => $q->where('order_id', $orderId))->with('orderItem:id,sku,product_name')->get()->mapWithKeys(fn ($i) => [$i->id => "{$i->orderItem->sku} — {$i->orderItem->product_name} (fulfilled {$i->quantity})"])->all();
                    })->required()->distinct()->searchable(),
                    TextInput::make('quantity')->label('Return Qty')->integer()->minValue(1)->default(1)->required(),
                    Select::make('return_reason')->options(collect(CustomerReturnReason::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()])->all())->required(),
                    Textarea::make('reason_notes')->maxLength(2000),
                ])->columns(2),
            ]),
        ]);
    }

    private static function isMarketplaceOrder(mixed $orderId): bool
    {
        return $orderId !== null && self::authorizedOrderQuery()->whereKey($orderId)->whereHas('warehouse', fn ($query) => $query->where('location_type', InventoryLocationType::MarketplaceFulfilment->value))->exists();
    }

    private static function fulfillmentLocationName(mixed $orderId): ?string
    {
        return self::authorizedOrderQuery()->whereKey($orderId)->with('warehouse:id,name')->first()?->warehouse?->name;
    }

    /** @return array<int, string> */
    private static function fulfilledOrderOptions(): array
    {
        return self::authorizedOrderQuery()
            ->where('status', OrderStatus::Fulfilled)
            ->with('platform:id,name')->orderByDesc('id')->limit(100)->get(['id', 'reference', 'marketplace_platform_id'])
            ->mapWithKeys(fn (Order $order): array => [$order->id => $order->reference.' · '.($order->platform?->name ?? 'Manual')])->all();
    }

    private static function singleFulfillmentItemId(mixed $orderId): ?int
    {
        if (! filled($orderId) || ! self::authorizedOrderQuery()->whereKey((int) $orderId)->exists()) {
            return null;
        }
        $ids = OrderFulfillmentItem::query()->whereHas('fulfillment', fn ($query) => $query->where('order_id', (int) $orderId))->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private static function authorizedOrderQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OrderReadService::class)->orders($user)
            : Order::query()->whereRaw('1 = 0');
    }
}
