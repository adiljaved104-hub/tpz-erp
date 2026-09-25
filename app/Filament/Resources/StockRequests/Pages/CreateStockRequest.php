<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\StockRequestPurpose;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Services\Inventory\StockRequestService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateStockRequest extends CreateRecord
{
    protected static string $resource = StockRequestResource::class;

    public string $idempotencyKey;

    public function mount(): void
    {
        $this->idempotencyKey = (string) str()->uuid();
        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockRequestService::class)->create(new CreateStockRequestData(
                purpose: StockRequestPurpose::from($data['purpose']),
                orderId: isset($data['order_id']) ? (int) $data['order_id'] : null,
                items: collect($data['items'])->map(fn (array $item): StockRequestItemData => new StockRequestItemData(
                    productInventoryId: (int) $item['product_inventory_id'],
                    quantity: (int) $item['quantity'],
                ))->all(),
                reason: (string) $data['reason'],
                idempotencyKey: $this->idempotencyKey,
            ), auth()->user());
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'items' => 'The Stock Request was not created. Your entered information has been kept; please review it and try again.',
            ]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Stock Request created successfully.';
    }
}
