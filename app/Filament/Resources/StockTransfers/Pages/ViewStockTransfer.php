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
use App\Services\StockTransfers\StockTransferAdvancedService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
            Action::make('partialShip')
                ->label('Ship Selected Quantities')
                ->color('warning')
                ->schema([$this->quantityLines('ship')])
                ->visible(fn (): bool => in_array($this->record->status, [StockTransferStatus::Draft, StockTransferStatus::Dispatched], true) && auth()->user()->can('dispatch', $this->record))
                ->action(fn (array $data) => $this->runTransferAction('Cannot ship transfer quantities', fn () => app(StockTransferAdvancedService::class)->ship($this->record, $this->lineQuantities($data), (string) str()->uuid(), auth()->user()))),
            Action::make('dispatch')
                ->label('Ship')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Confirm all Transfer lines physically left the source location.')
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Draft && auth()->user()->can('dispatch', $this->record))
                ->disabled(fn (): bool => $this->qcDispatchBlocked())
                ->tooltip(fn (): ?string => $this->qcDispatchBlocked() ? 'Dispatch is blocked until all Renewed units have current valid QC assignments.' : null)
                ->action(fn () => $this->runTransferAction(
                    'Cannot ship transfer',
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
            Action::make('partialReceive')
                ->label('Receive Selected Quantities')
                ->color('success')
                ->schema([$this->quantityLines('receive')])
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Dispatched && auth()->user()->can('receive', $this->record))
                ->action(fn (array $data) => $this->runTransferAction('Cannot receive transfer quantities', fn () => app(StockTransferAdvancedService::class)->receive($this->record, $this->lineQuantities($data), (string) str()->uuid(), auth()->user()))),
            Action::make('reportMarketplaceLoss')
                ->label('Report Marketplace Loss')->color('danger')
                ->schema([
                    Select::make('item_id')->label('Received item')->options(fn (): array => $this->record->items()->where('received_quantity', '>', 0)->get()->mapWithKeys(fn ($item): array => [$item->id => "{$item->sku} — received {$item->received_quantity}"])->all())->required(),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(),
                    TextInput::make('marketplace_reference')->label('Marketplace reference')->maxLength(255),
                    Textarea::make('notes')->maxLength(2000),
                ])
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Received && auth()->user()->can('receive', $this->record))
                ->action(fn (array $data) => $this->runTransferAction('Cannot report marketplace loss', fn () => app(StockTransferAdvancedService::class)->reportMarketplaceLoss($this->record, (int) $data['item_id'], (int) $data['quantity'], (string) str()->uuid(), auth()->user(), $data['marketplace_reference'] ?? null, $data['notes'] ?? null))),
            Action::make('investigateMarketplaceLoss')
                ->label('Start Loss Investigation')->color('warning')
                ->schema([Textarea::make('notes')->maxLength(2000)])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'pending')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot start loss investigation', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'pending')->oldest('id')->firstOrFail();

                    return app(StockTransferAdvancedService::class)->investigateMarketplaceLoss($loss, auth()->user(), $data['notes'] ?? null);
                })),
            Action::make('submitMarketplaceClaim')
                ->label('Submit Marketplace Claim')->color('primary')
                ->schema([TextInput::make('claim_reference')->required()->maxLength(255), TextInput::make('claim_amount')->numeric()->minValue(0), Textarea::make('notes')->maxLength(2000)])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'investigating')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot submit marketplace claim', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'investigating')->oldest('id')->firstOrFail();

                    return app(StockTransferAdvancedService::class)->submitMarketplaceClaim($loss, auth()->user(), $data['claim_reference'], $data['notes'] ?? null, $data['claim_amount'] ?? null);
                })),
            Action::make('closeMarketplaceClaim')
                ->label('Close Marketplace Claim')->color('success')
                ->schema([Select::make('outcome')->options(['reimbursed' => 'Reimbursed', 'rejected' => 'Rejected', 'resolved' => 'Resolved'])->required(), TextInput::make('reimbursed_amount')->numeric()->minValue(0), Textarea::make('notes')->maxLength(2000)])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'claim_submitted')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot close marketplace claim', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'claim_submitted')->oldest('id')->firstOrFail();

                    return app(StockTransferAdvancedService::class)->closeMarketplaceClaim($loss, auth()->user(), $data['outcome'], $data['notes'] ?? null, $data['reimbursed_amount'] ?? null);
                })),
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
                    && app(QcStockTransferReadinessService::class)->isRequired($this->record)
                    && $this->record->qcAssignments()->active()->exists())
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

    private function qcDispatchBlocked(): bool
    {
        $readiness = app(QcStockTransferReadinessService::class)->forTransfer($this->record);

        return $readiness['required'] > 0 && $readiness['status'] !== 'ready';
    }

    private function quantityLines(string $mode): Repeater
    {
        return Repeater::make('lines')->label(ucfirst($mode).' quantities')->defaultItems(0)->schema([
            Hidden::make('item_id')->required(),
            TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(),
        ])->default($this->record->items()->get()->map(fn ($item): array => ['item_id' => $item->id, 'quantity' => $mode === 'ship' ? max(0, $item->quantity - $item->dispatched_quantity) : max(0, $item->inTransitQuantity())])->filter(fn (array $line): bool => $line['quantity'] > 0)->values()->all());
    }

    /** @return array<int,int> */
    private function lineQuantities(array $data): array
    {
        return collect($data['lines'] ?? [])->mapWithKeys(fn (array $line): array => [(int) $line['item_id'] => (int) $line['quantity']])->all();
    }
}
