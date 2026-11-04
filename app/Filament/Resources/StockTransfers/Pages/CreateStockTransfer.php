<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Actions\StockTransfers\CreateStockTransfer as CreateStockTransferAction;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Services\StockTransfers\StockTransferReservationService;
use App\DTOs\StockTransfers\CreateStockTransferData;
use App\DTOs\StockTransfers\StockTransferItemData;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    public bool $shipImmediately = false;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateStockTransferAction::class)->handle(new CreateStockTransferData(
            sourceWarehouseId: (int) $data['source_warehouse_id'], destinationWarehouseId: (int) $data['destination_warehouse_id'], transferDate: (string) $data['transfer_date'],
            items: collect($data['items'])->map(fn (array $item): StockTransferItemData => new StockTransferItemData((int) $item['product_id'], (int) $item['quantity']))->all(),
            idempotencyKey: (string) str()->uuid(), handledByEmployeeId: isset($data['handled_by_employee_id']) ? (int) $data['handled_by_employee_id'] : null, notes: $data['notes'] ?? null,
        ), auth()->user());
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()->label('Create'),
            Action::make('createAndShip')
                ->label('Create & Ship')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Create this Draft transfer and immediately ship it using the existing authorization, QC, and inventory safeguards.')
                ->action(fn (): mixed => $this->createAndShip()),
            Action::make('createAndReserve')
                ->label('Create & Reserve')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('Create this Draft transfer and reserve the requested source stock using existing inventory safeguards.')
                ->action(fn (): mixed => $this->createAndReserve()),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterCreate(): void
    {
        if (! $this->shipImmediately) {
            return;
        }

        try {
            $this->record = app(DispatchStockTransfer::class)->handle($this->record, (string) str()->uuid(), auth()->user());
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Transfer was created but could not be shipped')->body($exception->getMessage())->send();
            throw $exception instanceof \LogicException ? $exception : new Halt;
        }
    }

    private function createAndShip(): void
    {
        $this->shipImmediately = true;
        $this->create();
    }

    private function createAndReserve(): void
    {
        $this->create();
        app(StockTransferReservationService::class)->reserve($this->record, (string) str()->uuid(), auth()->user());
        Notification::make()->success()->title('Transfer created and source stock reserved.')->send();
    }
}
