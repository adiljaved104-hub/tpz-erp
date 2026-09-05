<?php

namespace App\Filament\Resources\CustomerReturns\Pages;

use App\Actions\Returns\CreateCustomerReturn as CreateAction;
use App\DTOs\Returns\CreateCustomerReturnData;
use App\Exceptions\CustomerReturnException;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturn;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateCustomerReturn extends CreateRecord
{
    protected static string $resource = CustomerReturnResource::class;

    protected function handleRecordCreation(array $data): CustomerReturn
    {
        try {
            return app(CreateAction::class)->handle(new CreateCustomerReturnData((int) $data['order_id'], isset($data['receiving_warehouse_id']) ? (int) $data['receiving_warehouse_id'] : null, array_map(fn ($i) => ['order_fulfillment_item_id' => (int) $i['order_fulfillment_item_id'], 'quantity' => (int) $i['quantity'], 'return_reason' => $i['return_reason'], 'reason_notes' => $i['reason_notes'] ?? null], $data['items']), (string) str()->uuid(), $data['notes'] ?? null), auth()->user());
        } catch (CustomerReturnException $exception) {
            Notification::make()->danger()->title('Cannot create return')->body($exception->getMessage())->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
