<?php

namespace App\Filament\Resources\WebSalesOrders\Pages;

use App\Enums\InvoicePermission;
use App\Enums\ManualSourcingStatus;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductTitleMode;
use App\Enums\WebSalesDeliveryType;
use App\Enums\WebSalesPermission;
use App\Exceptions\DefaultWarehouseException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Concerns\InteractsWithQcOrderAssignments;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\CustomerReturn;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WebSalesManualSourcingLine;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Orders\ManualSourcingService;
use App\Services\Orders\OrderAmendmentService;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\WebSalesService;
use App\Services\Orders\WebSalesTaxInvoiceService;
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
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewWebSalesOrder extends ViewRecord
{
    use InteractsWithQcOrderAssignments;

    protected static string $resource = WebSalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->qcOrderActions(),
            Action::make('editWebSale')
                ->label('Edit Web Sale')
                ->icon('heroicon-o-pencil-square')
                ->color('primary')
                ->modalHeading('Edit Web Sale')
                ->modalDescription('Update customer information before shipment. Existing product, stock allocation, purchase and invoice records are preserved.')
                ->fillForm(fn (): array => $this->record->only(['customer_name', 'customer_phone', 'customer_address', 'notes']))
                ->schema([
                    TextInput::make('customer_name')->label('Customer Name')->required()->maxLength(255),
                    TextInput::make('customer_phone')->label('WhatsApp / Phone')->tel()->required()->maxLength(50),
                    Textarea::make('customer_address')->label('Customer Address')->maxLength(2000),
                    Textarea::make('notes')->label('Internal Notes')->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->canEditWebSale())
                ->action(fn (array $data) => $this->run('Could not edit Web Sale',
                    fn () => app(WebSalesService::class)->updateDetails($this->record, $data, auth()->user()))),
            Action::make('amendWebSaleItems')
                ->label('Amend Products / Prices')
                ->icon('heroicon-o-pencil')
                ->modalHeading('Controlled Web Sale Amendment')
                ->modalDescription('Change quantities or selling prices while the stock-reserved Web Sale remains unshipped. Quantity increases must include additional allocation sources. Every change is audited.')
                ->visible(fn (): bool => $this->allowed(WebSalesPermission::Update)
                    && ! $this->record->taxInvoices()->exists()
                    && ! $this->record->manualSourcingLines()->exists()
                    && auth()->user() instanceof User
                    && app(OrderAmendmentService::class)->canAmend($this->record, auth()->user()))
                ->fillForm(function (): array {
                    $canPrice = app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                        && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record);

                    return [
                        'items' => $this->record->items()->orderBy('line_number')->get()->map(fn ($item): array => [
                            'id' => $item->id,
                            'product' => $item->sku.' · '.$item->product_name,
                            'product_id' => $item->product_id,
                            'quantity' => $item->ordered_quantity,
                            'allocation_sources' => [],
                            ...($canPrice ? ['selling_price' => $item->selling_price] : []),
                        ])->all(),
                        'idempotency_key' => (string) Str::uuid(),
                    ];
                })
                ->schema([
                    Hidden::make('idempotency_key'),
                    Repeater::make('items')->label('Existing Products')->schema([
                        Hidden::make('id'),
                        Hidden::make('product_id'),
                        TextInput::make('product')->label('Product')->disabled()->dehydrated(false),
                        TextInput::make('quantity')->label('Quantity')->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('selling_price')->label('Selling Price (AED)')->numeric()->minValue(0)
                            ->visible(fn (): bool => app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                                && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record)),
                        Repeater::make('allocation_sources')->label('Additional Stock Sources')
                            ->visible(fn (): bool => app(InventoryAllocationService::class)->canSelectOrderSources(auth()->user()))
                            ->dehydrated(fn (): bool => app(InventoryAllocationService::class)->canSelectOrderSources(auth()->user()))
                            ->helperText('When increasing quantity, the additional source quantities must match the increase.')
                            ->schema([
                                Select::make('account_id')->label('Consume From')
                                    ->options(fn (Get $get): array => app(InventoryAllocationService::class)->orderSourceOptions(
                                        (int) $get('../../product_id'), (int) $this->record->warehouse_id, auth()->user()))
                                    ->required()->searchable()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('quantity')->label('Additional Quantity')->numeric()->integer()->minValue(1)->required(),
                            ])->columns(2)->defaultItems(0)->addActionLabel('Add Stock Source'),
                    ])->addable(false)->deletable(false)->reorderable(false),
                    Textarea::make('reason')->label('Reason for amendment')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(fn (array $data) => $this->run('Could not amend Web Sale', function () use ($data) {
                    app(OrderAmendmentService::class)->amend($this->record, $data, auth()->user());

                    return $this->record->refresh();
                })),
            Action::make('postShipmentCorrection')
                ->label('Post-Shipment Correction')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => auth()->user() instanceof User
                    && app(OrderAmendmentService::class)->canCorrectShipped($this->record, auth()->user()))
                ->modalHeading('Post-Shipment Correction')
                ->modalDescription('Only order date, selling price, corrected warehouse and external reference may change. Shipment, inventory movements and issued invoice snapshots remain immutable.')
                ->modalSubmitActionLabel('Confirm Correction')
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
                    DatePicker::make('order_date')->label('Corrected Order Date')->required(),
                    Select::make('warehouse_id')->label('Corrected Warehouse (shipment stays at original location)')
                        ->options(fn (): array => app(OrderFulfillmentLocationService::class)->options($this->record->marketplace_platform_id))
                        ->required()->searchable(),
                    TextInput::make('external_order_number')->label('External Reference')->maxLength(255),
                    Repeater::make('items')->label('Selling Prices (locked quantities)')
                        ->visible(fn (): bool => app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::ViewSellingPrice, $this->record)
                            && app(OrderAuthorization::class)->allows(auth()->user(), OrderPermission::EditSellingPrice, $this->record))
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('product')->disabled()->dehydrated(false),
                            TextInput::make('selling_price')->label('Selling Price (AED)')->numeric()->minValue(0)->required(),
                        ])->addable(false)->deletable(false)->reorderable(false),
                    Placeholder::make('correction_notice')->content(new HtmlString('<p class="text-sm text-warning-600">The original shipment, inventory movements and invoice snapshots will not be rewritten.</p>')),
                    Textarea::make('reason')->label('Reason for correction')->required()->minLength(5)->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $this->run('Could not correct shipped Web Sale', function () use ($data) {
                        app(OrderAmendmentService::class)->correctShipped($this->record, $data, auth()->user());

                        return $this->record->refresh();
                    });
                }),
            Action::make('assignManualSourcingSupplier')
                ->label('Set Manual Sourcing Supplier')
                ->icon('heroicon-o-user-circle')
                ->schema([
                    Select::make('line_id')->label('Manual Sourcing Product')
                        ->options(fn (): array => $this->assignableSupplierLines()
                            ->mapWithKeys(fn (WebSalesManualSourcingLine $line): array => [$line->id => "{$line->description} ({$line->quantity})"])->all())
                        ->required()->searchable(),
                    Select::make('supplier_id')->label('Supplier')
                        ->options(fn (): array => Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()->required(),
                ])
                ->modalDescription('Optional supplier master association. You can select one now or retain the supplier name provided with the original sale. Quick Purchase-style direct fulfillment can proceed without a Supplier master.')
                ->visible(fn (): bool => $this->record->status === OrderStatus::PendingReview
                    && $this->allowed(WebSalesPermission::Update)
                    && $this->assignableSupplierLines()->isNotEmpty())
                ->action(function (array $data): void {
                    $this->run('Cannot update manual sourcing supplier', function () use ($data) {
                        $line = WebSalesManualSourcingLine::query()
                            ->where('order_id', $this->record->id)
                            ->findOrFail((int) $data['line_id']);
                        app(ManualSourcingService::class)->assignSupplier($line, (int) $data['supplier_id'], auth()->user());

                        return $this->record->refresh();
                    });
                }),
            Action::make('confirmManualReceipt')
                ->label('Confirm Manual Sourcing Receipt')
                ->icon('heroicon-o-clipboard-document-check')
                ->schema([
                    Select::make('line_id')->label('Sourcing line')->options(fn (): array => $this->manualLines('warehouse_receipt')->mapWithKeys(fn (WebSalesManualSourcingLine $line): array => [$line->id => "{$line->description} ({$line->quantity})"])->all())->required()->live(),
                    Select::make('purchase_receipt_id')->label('Posted GRN')->options(fn (Get $get): array => $this->receiptOptions((int) $get('line_id')))->required(),
                ])
                ->visible(fn (): bool => $this->allowed(WebSalesPermission::Update) && $this->manualLines('warehouse_receipt')->isNotEmpty())
                ->action(fn (array $data) => $this->run('Could not confirm receipt', fn () => app(ManualSourcingService::class)->confirmReceipt(WebSalesManualSourcingLine::query()->findOrFail((int) $data['line_id']), PurchaseReceipt::query()->findOrFail((int) $data['purchase_receipt_id']), auth()->user()))),
            Action::make('taxInvoice')
                ->label(fn (): string => $this->record->taxInvoices()->exists() ? 'View Tax Invoice' : 'Generate Tax Invoice')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('primary')
                ->requiresConfirmation(fn (): bool => ! $this->record->taxInvoices()->exists())
                ->modalDescription('Creates an immutable Tax Invoice snapshot using the existing Order lines and product title rules.')
                ->schema(fn (): array => $this->record->taxInvoices()->exists() ? [] : [
                    Select::make('title_mode')->label('Product Title Mode')
                        ->options(collect(ProductTitleMode::cases())->mapWithKeys(fn (ProductTitleMode $mode): array => [$mode->value => $mode->label()])->all())
                        ->default(ProductTitleMode::Auto->value)->required(),
                ])
                ->visible(fn (): bool => $this->canUseTaxInvoice())
                ->action(function (array $data): void {
                    try {
                        $invoice = app(WebSalesTaxInvoiceService::class)->generateOrFind(
                            $this->record,
                            auth()->user(),
                            $data['title_mode'] ?? ProductTitleMode::Auto,
                        );
                        $this->redirect(TaxInvoiceResource::getUrl('view', ['record' => $invoice]));
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Tax Invoice could not be generated')
                            ->body(collect($exception->errors())->flatten()->unique()->join(' '))->send();
                        throw new Halt;
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->danger()->title('Tax Invoice could not be generated')
                            ->body('No Tax Invoice was created. Please try again or contact an administrator.')->send();
                        throw new Halt;
                    }
                }),
            Action::make('updateDelivery')->label('Courier / Tracking')->schema([
                Select::make('delivery_type')->options(WebSalesDeliveryType::class)->required()->live(),
                TextInput::make('courier_name')->required(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier')->visible(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier'),
                TextInput::make('tracking_number')->label('Tracking / AWB')->visible(fn (Get $get): bool => $this->deliveryValue($get('delivery_type')) === 'courier'),
            ])->fillForm(fn (): array => $this->record->only(['delivery_type', 'courier_name', 'tracking_number']))
                ->visible(fn (): bool => $this->allowed(WebSalesPermission::Update))->action(fn (array $data) => $this->run('Could not update delivery', fn () => app(WebSalesService::class)->updateDelivery($this->record, $data, auth()->user()))),
            Action::make('ship')->label('Mark Shipped')->color('success')->requiresConfirmation()
                ->disabled(fn (): bool => ! $this->qcDispatchReady())
                ->tooltip('Renewed lines require current QC devices and QC Dispatch Ship permission.')
                ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Reserved, OrderStatus::PendingReview], true) && $this->canFulfill())
                ->action(fn () => $this->run('Cannot ship Web Sale', fn () => app(WebSalesService::class)->ship($this->record, (string) str()->uuid(), auth()->user()))),
            Action::make('deliver')->label('Mark Delivered')->color('success')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === OrderStatus::Fulfilled && $this->record->delivered_at === null && $this->allowed(WebSalesPermission::Update))
                ->action(fn () => $this->run('Cannot mark Delivered', fn () => app(WebSalesService::class)->deliver($this->record, auth()->user()))),
            Action::make('createReturn')->label('Create Return')->url(fn (): string => CustomerReturnResource::getUrl('create', ['order_id' => $this->record->id]))
                ->visible(fn (): bool => $this->record->status === OrderStatus::Fulfilled && auth()->user()?->can('create', CustomerReturn::class) === true),
            Action::make('cancel')->label('Cancel Order')->color('danger')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => in_array($this->record->status, [OrderStatus::Draft, OrderStatus::Reserved], true) && $this->allowed(WebSalesPermission::Cancel))
                ->action(fn (array $data) => $this->run('Cannot cancel Web Sale', fn () => app(WebSalesService::class)->cancel($this->record, $data['reason'], (string) str()->uuid(), auth()->user()))),
        ];
    }

    private function run(string $title, callable $callback): void
    {
        try {
            $this->record = $callback();
            Notification::make()->success()->title('Web Sale updated')->send();
        } catch (ValidationException|InvalidOrderTransitionException|DefaultWarehouseException $exception) {
            Notification::make()->danger()->title($title)->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->join(' ') : $exception->getMessage())->send();
            throw new Halt;
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->warning()->title($title)->body('The shipment could not be completed. No changes were saved. Please contact an administrator if the problem continues.')->send();
            throw new Halt;
        }
    }

    private function canEditWebSale(): bool
    {
        return $this->allowed(WebSalesPermission::Update)
            && in_array($this->record->status, [
                OrderStatus::Draft, OrderStatus::Reserved, OrderStatus::PendingReview, OrderStatus::Confirmed,
            ], true)
            && ! $this->record->fulfillment()->exists()
            && ! $this->record->taxInvoices()->exists();
    }

    private function allowed(WebSalesPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, $permission, $this->record);
    }

    private function canFulfill(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill, $this->record);
    }

    private function canUseTaxInvoice(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $invoice = $this->record->taxInvoices()->oldest('id')->first();

        return $invoice !== null
            ? app(InvoiceAuthorization::class)->allows($user, InvoicePermission::View, $invoice)
            : app(InvoiceAuthorization::class)->allows($user, InvoicePermission::Create);
    }

    private function deliveryValue(mixed $value): mixed
    {
        return $value instanceof WebSalesDeliveryType ? $value->value : $value;
    }

    private function assignableSupplierLines(): Collection
    {
        return $this->record->manualSourcingLines()
            ->whereIn('source_mode', ['supplier_direct', 'warehouse_receipt'])
            ->where('status', ManualSourcingStatus::PendingSourcing->value)
            ->whereNull('purchase_id')
            ->whereNull('purchase_receipt_id')
            ->whereDoesntHave('supplierDirectDeliveries')
            ->get();
    }

    private function manualLines(string $mode): Collection
    {
        return $this->record->manualSourcingLines()->where('source_mode', $mode)->whereNotIn('status', ['fulfilled', 'cancelled'])->get();
    }

    private function receiptOptions(int $lineId): array
    {
        $line = WebSalesManualSourcingLine::query()->find($lineId);
        if (! $line) {
            return [];
        }

        return PurchaseReceipt::query()->whereHas('items', fn ($query) => $query->where('product_id', $line->product_id)->where('accepted_quantity', '>', 0))
            ->latest('received_at')->limit(100)->get()->mapWithKeys(fn (PurchaseReceipt $receipt): array => [$receipt->id => "{$receipt->reference} — {$receipt->received_at?->format('d M Y H:i')}"])->all();
    }
}
