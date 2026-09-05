<?php

namespace App\Filament\Resources\WebSalesOrders\Pages;

use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\WebSalesOrderData;
use App\Enums\OrderPermission;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\WebSalesService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateWebSalesOrder extends CreateRecord
{
    protected static string $resource = WebSalesOrderResource::class;

    protected static bool $canCreateAnother = false;

    public bool $completeDirectly = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $dto = new WebSalesOrderData(
                customerName: (string) $data['customer_name'],
                customerPhone: (string) $data['customer_phone'],
                channel: $data['web_sales_channel'] instanceof WebSalesChannel
                    ? $data['web_sales_channel']
                    : WebSalesChannel::from($data['web_sales_channel']),
                deliveryType: $data['delivery_type'] instanceof WebSalesDeliveryType
                    ? $data['delivery_type']
                    : WebSalesDeliveryType::from($data['delivery_type']),
                courierName: $data['courier_name'] ?? null,
                trackingNumber: $data['tracking_number'] ?? null,
                items: array_map(fn (array $item): OrderItemData => new OrderItemData(
                    productId: (int) $item['product_id'], quantity: (int) $item['quantity'], sellingPrice: (string) $item['selling_price'],
                    salesConfigurationId: filled($item['sales_configuration_id'] ?? null) ? (int) $item['sales_configuration_id'] : null,
                    upgradeRecipeId: filled($item['upgrade_recipe_id'] ?? null) ? (int) $item['upgrade_recipe_id'] : null,
                ), array_values($data['items'])),
                idempotencyKey: (string) $data['idempotency_key'],
                notes: $data['notes'] ?? null,
            );

            return $this->completeDirectly
                ? app(WebSalesService::class)->completeSale($dto, auth()->user())
                : app(WebSalesService::class)->createConfirmed($dto, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(str_starts_with($key, 'items.') ? 'data.items' : 'data.'.$key, $message);
                }
            }
            $this->dispatch('form-validation-error', livewireId: $this->getId());
            Notification::make()->danger()->title('Web Sale could not be saved')->body(collect($exception->errors())->flatten()->unique()->join(' '))->send();
            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save & Confirm');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('completeSale')->label('Complete Sale')->color('success')->requiresConfirmation()
                ->modalDescription('For Walk-in + Shop Pickup only. This immediately fulfils the sale from Main Warehouse.')
                ->visible(fn (): bool => $this->canFulfill())->action(fn () => $this->createCompleted()),
            $this->getCancelFormAction(),
        ];
    }

    public function create(bool $another = false): void
    {
        $this->completeDirectly = false;
        parent::create($another);
    }

    private function createCompleted(): void
    {
        $this->completeDirectly = true;
        parent::create();
    }

    private function canFulfill(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill);
    }
}
