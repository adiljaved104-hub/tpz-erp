<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\Orders\SaveAndReserveOrder;
use App\Actions\Orders\SaveAsShippedOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Enums\OrderPermission;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected static bool $canCreateAnother = false;

    public bool $shipDirectly = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $dto = new SaveAndReserveOrderData(
                warehouseId: (int) $data['warehouse_id'],
                platformId: filled($data['marketplace_platform_id'] ?? null) ? (int) $data['marketplace_platform_id'] : null,
                externalOrderNumber: $data['external_order_number'] ?? null,
                orderDate: (string) $data['order_date'],
                handledByEmployeeId: filled($data['handled_by_employee_id'] ?? null) ? (int) $data['handled_by_employee_id'] : null,
                notes: $data['notes'] ?? null,
                items: array_map(fn (array $item): OrderItemData => new OrderItemData(
                    productId: (int) $item['product_id'],
                    quantity: (int) $item['quantity'],
                    sellingPrice: (string) $item['selling_price'],
                    discountTotal: (string) ($item['discount_total'] ?? '0.00'),
                    vatRate: (string) ($item['vat_rate'] ?? '0.0000'),
                    notes: $item['notes'] ?? null,
                    salesConfigurationId: filled($item['sales_configuration_id'] ?? null) ? (int) $item['sales_configuration_id'] : null,
                    upgradeRecipeId: filled($item['upgrade_recipe_id'] ?? null) ? (int) $item['upgrade_recipe_id'] : null,
                ), array_values($data['items'])),
                idempotencyKey: (string) $data['idempotency_key'],
            );

            return $this->shipDirectly
                ? app(SaveAsShippedOrder::class)->handle($dto, auth()->user())
                : app(SaveAndReserveOrder::class)->handle($dto, auth()->user());
        } catch (ValidationException $exception) {
            $this->surfaceDomainValidation($exception);

            throw (new Halt)->rollBackDatabaseTransaction();
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save & Reserve');
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('saveAsShipped')
                ->label('Save as Shipped')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Use this only when all Products have already physically left the Warehouse.')
                ->visible(fn (): bool => $this->canFulfill())
                ->action(fn () => $this->createShipped()),
            $this->getCancelFormAction(),
        ];
    }

    protected function createShipped(): void
    {
        $this->shipDirectly = true;
        parent::create();
    }

    public function create(bool $another = false): void
    {
        $this->shipDirectly = false;
        parent::create($another);
    }

    private function surfaceDomainValidation(ValidationException $exception): void
    {
        $itemKeys = array_keys($this->form->getRawState()['items'] ?? []);
        $notificationMessages = [];
        $hasInlineErrors = false;

        foreach ($exception->errors() as $key => $messages) {
            $path = $this->visibleFormErrorPath($key, $itemKeys);

            foreach ($messages as $message) {
                if ($path !== null) {
                    $this->addError($path, $message);
                    $hasInlineErrors = true;

                    continue;
                }

                $notificationMessages[] = $message;
            }
        }

        if ($hasInlineErrors) {
            $this->dispatch('form-validation-error', livewireId: $this->getId());
        }

        if ($notificationMessages !== []) {
            Notification::make()
                ->danger()
                ->title('Order could not be saved')
                ->body(implode(' ', array_unique($notificationMessages)))
                ->send();
        }
    }

    /** @param array<int, int|string> $itemKeys */
    private function visibleFormErrorPath(string $key, array $itemKeys): ?string
    {
        $topLevelFields = [
            'marketplace_platform_id',
            'warehouse_id',
            'external_order_number',
            'order_date',
            'handled_by_employee_id',
            'notes',
        ];

        if ($key === 'external_identity_hash') {
            return 'data.external_order_number';
        }

        if (in_array($key, $topLevelFields, true)) {
            return "data.{$key}";
        }

        if (! preg_match('/^items\.(\d+)\.(product_id|quantity|selling_price)$/', $key, $matches)) {
            return null;
        }

        $index = (int) $matches[1];
        $itemKey = $itemKeys[$index] ?? $index;

        return "data.items.{$itemKey}.{$matches[2]}";
    }

    private function canFulfill(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OrderAuthorization::class)->allows($user, OrderPermission::Fulfill);
    }
}
