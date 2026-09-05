<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\FulfillOrder;
use App\Actions\Orders\ReserveDraftOrder;
use App\DTOs\Orders\CancelOrderData;
use App\Enums\OrderStatus;
use App\Enums\TaskLinkedType;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::Order, $this->record),
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
