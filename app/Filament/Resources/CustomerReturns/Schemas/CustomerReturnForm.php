<?php

namespace App\Filament\Resources\CustomerReturns\Schemas;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnReason;
use App\Enums\InventoryLocationType;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Orders\OrderReferenceSearchService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
            Section::make('Find the Order and confirm receipt')->columns(2)->schema([
                Select::make('order_id')->label('Order ID / Marketplace Order ID')
                    ->default(fn (): ?int => request()->integer('order_id') ?: null)
                    ->getSearchResultsUsing(fn (string $search): array => self::fulfilledOrderSearch($search))
                    ->getOptionLabelUsing(fn ($value): ?string => self::fulfilledOrderLabel($value))
                    ->searchPrompt('Search by ERP Order Number or External Order ID.')
                    ->noSearchResultsMessage('No eligible fulfilled Orders found.')
                    ->searchable()->required()->live()
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
                Toggle::make('physical_received')->label('Package physically received at this warehouse')
                    ->helperText('Leave off to save the return as a draft. Turn on only after the package arrives.')
                    ->default(false)->live()->columnSpanFull()
                    ->visible(fn (): bool => auth()->check() && app(CustomerReturnAuthorization::class)
                        ->allows(auth()->user(), CustomerReturnPermission::Receive)),
                Textarea::make('notes')->maxLength(5000)->columnSpanFull(),
            ]),
            Section::make('Returned products and QC')->description('Select each product and quantity. Use Save Pending if any inspection is unfinished.')->schema([
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
                    Select::make('qc_outcome')->label('QC result')->options([
                        'pending' => 'Pending inspection', 'pass' => 'PASS — return to sellable',
                        'fail' => 'FAIL — move to damaged stock',
                        'mixed' => 'Mixed — split PASS and FAIL quantities',
                        'quarantine' => 'Wrong / Different Item, serial mismatch or empty package — hold for review',
                    ])->default('pending')->required()->live()
                        ->visible(fn (Get $get): bool => (bool) $get('../../physical_received')),
                    Select::make('problem_type')->label('Actual problem')->options(fn (Get $get): array => match ($get('qc_outcome')) {
                        'fail', 'mixed' => ['damaged' => 'Damaged', 'defective' => 'Defective', 'missing_accessories' => 'Missing accessories', 'other' => 'Other problem'],
                        'quarantine' => ['wrong_item' => 'Different product', 'serial_mismatch' => 'Serial number does not match', 'empty_package' => 'Empty package'],
                        default => [],
                    })->required(fn (Get $get): bool => in_array($get('qc_outcome'), ['fail', 'mixed', 'quarantine'], true))
                        ->visible(fn (Get $get): bool => in_array($get('qc_outcome'), ['fail', 'mixed', 'quarantine'], true)),
                    TextInput::make('sellable_quantity')->label('PASS quantity')->integer()->minValue(1)
                        ->required(fn (Get $get): bool => $get('qc_outcome') === 'mixed')
                        ->visible(fn (Get $get): bool => $get('qc_outcome') === 'mixed'),
                    TextInput::make('damaged_quantity')->label('FAIL quantity')->integer()->minValue(1)
                        ->required(fn (Get $get): bool => $get('qc_outcome') === 'mixed')
                        ->visible(fn (Get $get): bool => $get('qc_outcome') === 'mixed'),
                    TextInput::make('actual_custody_quantity')->label('Actual Items Received')
                        ->integer()->minValue(0)->helperText('Use 0 for an empty package. These items are not added to the original product inventory.')
                        ->required(fn (Get $get): bool => $get('qc_outcome') === 'quarantine')
                        ->visible(fn (Get $get): bool => $get('qc_outcome') === 'quarantine'),
                    TextInput::make('actual_product_name')->label('Actual item description')->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('qc_outcome') === 'quarantine'),
                    TextInput::make('actual_serial_number')->label('Actual serial number, if present')->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('qc_outcome') === 'quarantine'),
                    Textarea::make('qc_remarks')->label('QC remarks')->maxLength(5000)
                        ->visible(fn (Get $get): bool => in_array($get('qc_outcome'), ['fail', 'mixed', 'quarantine'], true)),
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
    private static function fulfilledOrderSearch(string $search): array
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(OrderReferenceSearchService::class)->options($user, $search, [OrderStatus::Fulfilled])
            : [];
    }

    private static function fulfilledOrderLabel(mixed $orderId): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User || ! filled($orderId)) {
            return null;
        }

        $order = app(OrderReferenceSearchService::class)
            ->findAuthorized($user, (int) $orderId, [OrderStatus::Fulfilled]);

        return $order ? app(OrderReferenceSearchService::class)->label($order) : null;
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
            ? app(OrderReferenceSearchService::class)->query($user)
            : Order::query()->whereRaw('1 = 0');
    }
}
