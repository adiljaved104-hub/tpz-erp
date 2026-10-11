<?php

namespace App\Filament\Resources\StockTransfers\Pages;

use App\Actions\StockTransfers\CancelStockTransfer;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\ReturnStockTransferToSource;
use App\Enums\QcPermission;
use App\Enums\StockTransferPermission;
use App\Enums\StockTransferStatus;
use App\Exceptions\InsufficientInventoryException;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Exceptions\InventoryInvariantException;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\QcStockTransferAssignment;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Qc\QcStockTransferAssignmentService;
use App\Services\Qc\QcStockTransferReadinessService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Throwable;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispatch')
                ->label('Dispatch')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Confirm all Transfer lines physically left the source location.')
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Draft && auth()->user()->can('dispatch', $this->record))
                ->action(fn () => $this->runTransferAction(
                    'Cannot dispatch transfer',
                    fn () => app(DispatchStockTransfer::class)->handle($this->record, (string) str()->uuid(), auth()->user()),
                    appendSourceLocation: true,
                )),
            Action::make('receive')
                ->label('Receive')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Confirm all In Transit lines physically arrived at the destination.')
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Dispatched && auth()->user()->can('receive', $this->record))
                ->action(fn () => $this->runTransferAction(
                    'Cannot receive transfer',
                    fn () => app(ReceiveStockTransfer::class)->handle($this->record, (string) str()->uuid(), auth()->user()),
                )),
            Action::make('cancel')
                ->label('Cancel Draft')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Draft && auth()->user()->can('cancel', $this->record))
                ->action(fn (array $data) => $this->runTransferAction(
                    'Cannot cancel transfer',
                    fn () => app(CancelStockTransfer::class)->handle($this->record, $data['reason'], (string) str()->uuid(), auth()->user()),
                )),
            Action::make('releaseQcAssignment')
                ->label('Release QC Device')
                ->color('danger')
                ->schema([
                    Select::make('assignment_id')->label('Assigned QC Device')->required()->options(fn (): array => $this->record->qcAssignments()
                        ->active()->with('device')->orderBy('id')->get()
                        ->mapWithKeys(fn (QcStockTransferAssignment $assignment): array => [$assignment->id => $assignment->device->reference.' · '.$assignment->device->serial])->all()),
                    Textarea::make('release_reason')->label('Reason')->required()->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Draft
                    && auth()->user() !== null
                    && app(StockTransferAuthorization::class)->allows(auth()->user(), StockTransferPermission::Dispatch, $this->record)
                    && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ViewOrderAssignments)
                    && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ReleaseMarketplaceTransferDevice)
                    && app(QcStockTransferReadinessService::class)->isRequired($this->record))
                ->action(function (array $data): void {
                    $assignment = QcStockTransferAssignment::query()->where('stock_transfer_id', $this->record->id)->findOrFail($data['assignment_id']);
                    app(QcStockTransferAssignmentService::class)->release($this->record, $assignment, $data['release_reason'], auth()->user());
                    $this->record->refresh();
                    Notification::make()->success()->title('QC device released from this Draft transfer.')->send();
                }),
            Action::make('return')
                ->label('Confirm Returned to Source')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Dispatched && auth()->user()->can('cancel', $this->record))
                ->action(fn (array $data) => $this->runTransferAction(
                    'Cannot return transfer to source',
                    fn () => app(ReturnStockTransferToSource::class)->handle($this->record, $data['reason'], (string) str()->uuid(), auth()->user()),
                )),
        ];
    }

    /** @param callable(): mixed $operation */
    private function runTransferAction(string $title, callable $operation, bool $appendSourceLocation = false): void
    {
        try {
            $operation();
            $this->record->refresh();
        } catch (InventoryInvariantException|InvalidStockTransferTransitionException $exception) {
            Notification::make()
                ->danger()
                ->title($title)
                ->body($this->businessFailureMessage($exception, $appendSourceLocation))
                ->send();

            throw new Halt;
        }
    }

    private function businessFailureMessage(Throwable $exception, bool $appendSourceLocation): string
    {
        $message = rtrim($exception->getMessage(), ". \t\n\r\0\x0B");

        if ($appendSourceLocation && $exception instanceof InsufficientInventoryException) {
            $sourceName = $this->record->sourceWarehouse()->value('name');

            if (filled($sourceName)) {
                $message .= " at {$sourceName}";
            }
        }

        return "{$message}.";
    }
}
