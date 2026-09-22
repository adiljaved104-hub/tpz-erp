<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\ReserveDraftOrder;
use App\DTOs\Orders\CancelOrderData;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\TaskLinkedType;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderAmendmentService;
use App\Services\Orders\OrderFulfillmentLocationService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::Order, $this->record),
            Action::make('amendOrder')
                ->label('Amend Order')
                ->visible(fn (): bool => app(OrderAmendmentService::class)->canAmend($this->record, auth()->user()))
                ->modalHeading('Controlled Order Amendment')
                ->modalDescription(function (): string {
                    $expires = app(OrderAmendmentService::class)->expiresAt($this->record);

                    return 'Normal window expires '.($expires?->format('d M Y h:i A') ?? 'unknown').'. '
                        .($expires !== null && now()->greaterThan($expires)
                            ? 'You are using an authorized after-window override; it will be recorded.'
                            : 'No override is currently required.');
                })
                ->fillForm(function (): array {
                    $canViewPrice = app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record);

                    return [
                        'external_order_number' => $this->record->external_order_number,
                        'items' => $this->record->items()->orderBy('line_number')->get()->map(fn ($item): array => [
                            'id' => $item->id, 'product' => $item->sku.' · '.$item->product_name,
                            'quantity' => $item->ordered_quantity,
                            ...($canViewPrice ? ['selling_price' => $item->selling_price] : []),
                        ])->all(),
                        'idempotency_key' => (string) Str::uuid(),
                    ];
                })
                ->schema([
                    Hidden::make('idempotency_key'),
                    TextInput::make('external_order_number')->label('External Order Number')->maxLength(255),
                    Repeater::make('items')->label('Order Items')->schema([
                        Hidden::make('id'),
                        TextInput::make('product')->label('Product')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('Quantity')->numeric()->minValue(1)->required(),
                        TextInput::make('selling_price')->label('Selling Price (AED)')->numeric()->minValue(0)
                            ->visible(fn (): bool => app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                                && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record)),
                    ])->addable(false)->deletable(false)->reorderable(false),
                    Textarea::make('reason')->label('Reason for amendment')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    app(OrderAmendmentService::class)->amend($this->record, $data, auth()->user());
                    $this->record->refresh();
                    Notification::make()->success()->title('Order amendment recorded')->send();
                }),
            Action::make('postShipmentCorrection')
                ->label('Post-Shipment Correction')
                ->visible(fn (): bool => app(OrderAmendmentService::class)->canCorrectShipped($this->record, auth()->user()))
                ->modalHeading('Post-Shipment Correction')
                ->modalDescription(function (): string {
                    $expires = app(OrderAmendmentService::class)->expiresAt($this->record);

                    return 'Only date, selling price, corrected warehouse and external reference may change. Shipment and inventory history remain unchanged. '
                        .'Normal window expires '.($expires?->format('d M Y h:i A') ?? 'unknown').'. '
                        .($expires !== null && now()->greaterThan($expires) ? 'Authorized after-window override will be recorded.' : 'No override is required.');
                })
                ->modalSubmitActionLabel('Confirm Post-Shipment Correction')
                ->fillForm(function (): array {
                    $canEditPrices = app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record);

                    return [
                        'order_date' => $this->record->order_date->format('Y-m-d'),
                        'warehouse_id' => app(OrderAmendmentService::class)->correctedWarehouseId($this->record) ?? $this->record->warehouse_id,
                        'external_order_number' => $this->record->external_order_number,
                        'items' => $canEditPrices ? $this->record->items()->orderBy('line_number')->get()->map(fn ($item): array => [
                            'id' => $item->id, 'product' => $item->sku.' · '.$item->product_name, 'selling_price' => $item->selling_price,
                        ])->all() : [],
                        'idempotency_key' => (string) Str::uuid(),
                    ];
                })
                ->schema([
                    Hidden::make('idempotency_key'),
                    DatePicker::make('order_date')->label('Corrected Order Date')->required()->live(),
                    Select::make('warehouse_id')->label('Corrected Warehouse (shipment stays at original location)')
                        ->options(fn (): array => app(OrderFulfillmentLocationService::class)->options($this->record->marketplace_platform_id))
                        ->required()->searchable()->live(),
                    TextInput::make('external_order_number')->label('External Order Number')->maxLength(255)->live(onBlur: true),
                    Repeater::make('items')->label('Selling Prices · quantities and products are locked')
                        ->visible(fn (): bool => app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                            && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record))
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('product')->label('Product')->disabled()->dehydrated(false),
                            TextInput::make('selling_price')->label('Selling Price (AED)')->numeric()->minValue(0)->required()->live(onBlur: true),
                        ])->addable(false)->deletable(false)->reorderable(false),
                    Placeholder::make('change_preview')->label('Review changed fields before confirming')
                        ->content(fn (Get $get): HtmlString => $this->postShipmentChangePreview($get)),
                    Textarea::make('reason')->label('Reason for correction')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    app(OrderAmendmentService::class)->correctShipped($this->record, $data, auth()->user());
                    $this->record->refresh();
                    Notification::make()->success()->title('Post-shipment correction recorded')->send();
                }),
            Action::make('amendmentHistory')
                ->label('Amendment History')
                ->visible(fn (): bool => auth()->user()->can('view', $this->record) && $this->record->amendments()->exists())
                ->modalSubmitAction(false)
                ->modalContent(function () {
                    $canViewPrices = app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record);

                    return view('filament.resources.orders.partials.amendment-history', [
                        'amendments' => $this->record->amendments()->with(['amendedBy:id,name', 'lines' => fn ($query) => $query
                            ->whereNotIn('field', $canViewPrices ? ['recipe_snapshot'] : ['recipe_snapshot', 'selling_price'])
                            ->orderBy('id')])->get(),
                    ]);
                }),
            Action::make('saveAndReserve')
                ->label('Save & Reserve')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === OrderStatus::Draft && auth()->user()->can('update', $this->record))
                ->action(fn () => app(ReserveDraftOrder::class)->handle($this->record, auth()->user())),
            Action::make('saveAsShipped')
                ->label('Save as Shipped')->color('success')->requiresConfirmation()
                ->modalDescription('Confirm that every Product on this Order has physically left the Warehouse.')
                ->visible(fn (): bool => $this->record->status === OrderStatus::Reserved && auth()->user()->can('order.fulfill', $this->record))
                ->action(fn () => app(FulfillOrder::class)->handle($this->record, (string) str()->uuid(), auth()->user())),
            Action::make('cancel')
                ->label('Cancel Order')->color('danger')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Draft, OrderStatus::Reserved], true) && auth()->user()->can('cancel', $this->record))
                ->action(fn (array $data) => app(CancelOrder::class)->handle(
                    $this->record,
                    new CancelOrderData($data['reason'], (string) str()->uuid()),
                    auth()->user(),
                )),
        ];
    }

    private function postShipmentChangePreview(Get $get): HtmlString
    {
        $changes = [];
        $date = $get('order_date');
        if (filled($date) && $date !== $this->record->order_date->format('Y-m-d')) {
            $changes[] = 'Order Date: '.e($this->record->order_date->format('Y-m-d')).' → '.e($date);
        }
        $currentWarehouseId = app(OrderAmendmentService::class)->correctedWarehouseId($this->record) ?? $this->record->warehouse_id;
        if (filled($get('warehouse_id')) && (int) $get('warehouse_id') !== $currentWarehouseId) {
            $changes[] = 'Corrected Warehouse: '.e((string) $currentWarehouseId).' → '.e((string) $get('warehouse_id'));
        }
        $external = trim((string) $get('external_order_number')) ?: null;
        if ($external !== $this->record->external_order_number) {
            $changes[] = 'External Reference: '.e($this->record->external_order_number ?? '—').' → '.e($external ?? '—');
        }
        if (app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)) {
            $original = $this->record->items()->pluck('selling_price', 'id');
            foreach ((array) $get('items') as $line) {
                $id = (int) ($line['id'] ?? 0);
                if ($original->has($id) && isset($line['selling_price']) && is_numeric($line['selling_price']) && bccomp((string) $line['selling_price'], (string) $original[$id], 2) !== 0) {
                    $changes[] = 'Line #'.e((string) $id).' Selling Price: '.e((string) $original[$id]).' → '.e((string) $line['selling_price']);
                }
            }
        }

        return new HtmlString($changes === []
            ? '<span class="text-gray-500">No changed fields yet.</span>'
            : '<ul class="list-disc space-y-1 ps-5 font-semibold text-warning-700 dark:text-warning-400"><li>'.implode('</li><li>', $changes).'</li></ul>');
    }
}
