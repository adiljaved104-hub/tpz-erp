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
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
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
}
