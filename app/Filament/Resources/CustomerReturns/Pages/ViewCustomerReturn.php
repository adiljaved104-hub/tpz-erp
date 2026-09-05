<?php

namespace App\Filament\Resources\CustomerReturns\Pages;

use App\Actions\Returns\CancelCustomerReturn;
use App\Actions\Returns\InspectCustomerReturnItem;
use App\Actions\Returns\ReceiveCustomerReturn;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\TaskLinkedType;
use App\Exceptions\CustomerReturnException;
use App\Exceptions\ReturnRefundException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturnItem;
use App\Models\Warehouse;
use App\Services\Returns\MarketplaceReturnService;
use App\Services\Returns\ReturnRefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ViewCustomerReturn extends ViewRecord
{
    protected static string $resource = CustomerReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::CustomerReturn, $this->record),
            Action::make('recordRefund')->label('Record Customer Refund')->color('warning')
                ->visible(fn (): bool => $this->record->refund === null && auth()->user()->can('return.record_refund', $this->record))
                ->schema([
                    TextInput::make('refund_amount')->label('Refund Amount')->prefix('AED')->numeric()->required()->minValue(0.01)
                        ->default(fn (): ?string => app(ReturnRefundService::class)->suggestedAmountForReturn($this->record)),
                    DatePicker::make('refund_date')->label('Refund Date')->default(today())->maxDate(today())->required(),
                    TextInput::make('external_refund_reference')->label('External Refund Reference')->maxLength(255),
                    Textarea::make('note')->label('Note')->maxLength(5000),
                ])->action(function (array $data): void {
                    try {
                        app(ReturnRefundService::class)->recordForReturn(
                            $this->record,
                            (string) $data['refund_amount'],
                            $data['refund_date'],
                            $data['external_refund_reference'] ?? null,
                            $data['note'] ?? null,
                            (string) str()->uuid(),
                            auth()->user(),
                        );
                        $this->record->refresh()->load(['refund', 'claims']);
                        Notification::make()->success()->title('Customer refund recorded')->send();
                    } catch (ReturnRefundException|ValidationException|AuthorizationException $exception) {
                        $message = $exception instanceof ValidationException
                            ? collect($exception->errors())->flatten()->first()
                            : ($exception->getMessage() ?: 'You are not authorized to record this refund.');
                        Notification::make()->danger()->title('Cannot record customer refund')->body($message)->send();
                        throw new Halt;
                    }
                }),
            Action::make('marketplaceDisposition')->label('Record Marketplace Disposition')->schema([
                Select::make('item_id')->label('Return Item')->options(fn () => $this->record->items->mapWithKeys(fn ($i) => [$i->id => "{$i->sku_snapshot} — {$i->product_name_snapshot}"]))->required(),
                TextInput::make('sellable_quantity')->label('Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('non_sellable_quantity')->label('Non-Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.record_marketplace_disposition', $this->record))->action(fn (array $data) => $this->run('Cannot record marketplace disposition', fn () => app(MarketplaceReturnService::class)->disposition(CustomerReturnItem::findOrFail($data['item_id']), (int) $data['sellable_quantity'], (int) $data['non_sellable_quantity'], (string) str()->uuid(), auth()->user(), $data['notes'] ?? null))),
            Action::make('requestRemoval')->label('Request Return / Removal')->schema([
                Select::make('item_id')->label('Return Item')->options(fn () => $this->record->items->mapWithKeys(fn ($i) => [$i->id => "{$i->sku_snapshot} — {$i->product_name_snapshot}"]))->required(),
                TextInput::make('quantity')->integer()->minValue(1)->required(),
                Select::make('destination_id')->label('Company Receiving Location')->options(fn () => Warehouse::query()->active()->whereNotIn('location_type', [InventoryLocationType::Transit, InventoryLocationType::MarketplaceFulfilment])->pluck('name', 'id'))->default(fn () => $this->record->platform?->default_return_receiving_warehouse_id)->required(),
                TextInput::make('external_reference')->label('External Removal Reference')->maxLength(255), Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => auth()->user()->can('marketplace_return.request_removal'))->action(fn (array $data) => $this->run('Cannot request marketplace removal', fn () => app(MarketplaceReturnService::class)->requestRemoval(CustomerReturnItem::findOrFail($data['item_id']), (int) $data['quantity'], (int) $data['destination_id'], (string) str()->uuid(), auth()->user(), $data['external_reference'] ?? null, $data['notes'] ?? null))),
            Action::make('receive')->label('Receive Return')->color('warning')->requiresConfirmation()->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.receive', $this->record))->action(fn () => $this->run('Cannot receive return', fn () => app(ReceiveCustomerReturn::class)->handle($this->record, auth()->user()))),
            Action::make('inspect')->label('QC Return')->color('success')->schema([
                Select::make('item_id')->label('Return Item')->options(fn () => $this->record->items->mapWithKeys(fn ($i) => [$i->id => "{$i->sku_snapshot} — {$i->product_name_snapshot} ({$i->return_quantity})"]))->required(),
                TextInput::make('sellable_quantity')->label('Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('damaged_quantity')->label('Damaged Qty')->integer()->minValue(0)->default(0)->required(),
                Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => $this->record->status === CustomerReturnStatus::QcPending && auth()->user()->can('return.inspect', $this->record))->action(fn (array $data) => $this->run('Cannot inspect return', fn () => app(InspectCustomerReturnItem::class)->handle(CustomerReturnItem::findOrFail($data['item_id']), new InspectCustomerReturnItemData((int) $data['sellable_quantity'], (int) $data['damaged_quantity'], (string) str()->uuid(), $data['notes'] ?? null), auth()->user()))),
            Action::make('cancel')->label('Cancel Draft')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])->visible(fn () => $this->record->status === CustomerReturnStatus::Draft && auth()->user()->can('return.cancel', $this->record))->action(fn (array $data) => $this->run('Cannot cancel return', fn () => app(CancelCustomerReturn::class)->handle($this->record, $data['reason'], auth()->user()))),
        ];
    }

    private function run(string $title, callable $operation): void
    {
        try {
            $operation();
            $this->record->refresh();
        } catch (CustomerReturnException $e) {
            Notification::make()->danger()->title($title)->body($e->getMessage())->send();
            throw new Halt;
        }
    }
}
