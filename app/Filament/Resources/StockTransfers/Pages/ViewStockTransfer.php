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
use App\Models\StockTransferMarketplaceLoss;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Qc\QcStockTransferAssignmentService;
use App\Services\Qc\QcStockTransferReadinessService;
use App\Services\StockTransfers\StockTransferAdvancedService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Throwable;
use Illuminate\Validation\ValidationException;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('partialShip')
                ->label('Ship Selected Quantities')
                ->color('warning')
                ->schema(fn (): array => $this->quantityFields('ship'))
                ->visible(fn (): bool => in_array($this->record->status, [StockTransferStatus::Draft, StockTransferStatus::Dispatched], true) && auth()->user()->can('dispatch', $this->record))
                ->action(fn (array $data) => $this->runTransferAction('Cannot ship transfer quantities', fn () => app(StockTransferAdvancedService::class)->ship($this->record, $this->lineQuantities($data, 'ship'), (string) str()->uuid(), auth()->user()))),
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
                ->schema(fn (): array => $this->quantityFields('receive'))
                ->visible(fn (): bool => $this->record->status === StockTransferStatus::Dispatched && auth()->user()->can('receive', $this->record))
                ->action(fn (array $data) => $this->runTransferAction('Cannot receive transfer quantities', fn () => app(StockTransferAdvancedService::class)->receive($this->record, $this->lineQuantities($data, 'receive'), (string) str()->uuid(), auth()->user()))),
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
                ->schema([
                    $this->lossSelector('pending'),
                    Textarea::make('notes')->maxLength(2000),
                ])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'pending')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot start loss investigation', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'pending')->findOrFail((int) $data['loss_id']);

                    return app(StockTransferAdvancedService::class)->investigateMarketplaceLoss($loss, auth()->user(), $data['notes'] ?? null);
                })),
            Action::make('submitMarketplaceClaim')
                ->label('Submit Marketplace Claim')->color('primary')
                ->schema([$this->lossSelector('investigating'), TextInput::make('claim_reference')->required()->maxLength(255), TextInput::make('claim_amount')->numeric()->minValue(0), Textarea::make('notes')->maxLength(2000)])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'investigating')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot submit marketplace claim', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'investigating')->findOrFail((int) $data['loss_id']);

                    return app(StockTransferAdvancedService::class)->submitMarketplaceClaim($loss, auth()->user(), $data['claim_reference'], $data['notes'] ?? null, $data['claim_amount'] ?? null);
                })),
            Action::make('closeMarketplaceClaim')
                ->label('Close Marketplace Claim')->color('success')
                ->schema([$this->lossSelector('claim_submitted'), Select::make('outcome')->options(['reimbursed' => 'Reimbursed', 'rejected' => 'Rejected', 'resolved' => 'Resolved'])->required(), TextInput::make('reimbursed_amount')->numeric()->minValue(0), Textarea::make('notes')->maxLength(2000)])
                ->visible(fn (): bool => auth()->user()->can('receive', $this->record) && $this->record->marketplaceLosses()->where('status', 'claim_submitted')->exists())
                ->action(fn (array $data) => $this->runTransferAction('Cannot close marketplace claim', function () use ($data) {
                    $loss = $this->record->marketplaceLosses()->where('status', 'claim_submitted')->findOrFail((int) $data['loss_id']);

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

    private function lossSelector(string $status): Select
    {
        return Select::make('loss_id')
            ->label('Marketplace Loss / Product / Quantity')
            ->options(fn (): array => $this->record->marketplaceLosses()
                ->where('status', $status)
                ->with('item')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn (StockTransferMarketplaceLoss $loss): array => [
                    $loss->id => ($loss->item?->sku ?? 'Unknown SKU')
                        .' · '.($loss->item?->product_name ?? 'Product')
                        .' · Reported Qty: '.$loss->quantity
                        .($loss->marketplace_reference ? ' · '.$loss->marketplace_reference : ''),
                ])->all())
            ->searchable()
            ->required();
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

    /** @return array<int, Section> */
    private function quantityFields(string $mode): array
    {
        return $this->record->items()->get()
            ->filter(fn ($item): bool => $this->eligibleQuantity($item, $mode) > 0)
            ->map(function ($item) use ($mode): Section {
                $eligible = $this->eligibleQuantity($item, $mode);

                return Section::make("{$item->sku} · {$item->product_name}")
                    ->description("Requested: {$item->quantity} · Shipped: {$item->dispatched_quantity} · Received: {$item->received_quantity} · Eligible now: {$eligible}")
                    ->schema([
                        TextInput::make('quantity_'.$item->id)
                            ->label($mode === 'ship' ? 'Ship Now' : 'Receive Now')
                            ->numeric()->integer()->minValue(0)->maxValue($eligible)
                            ->default($eligible)->required(),
                    ])->compact();
            })->values()->all();
    }

    private function eligibleQuantity(mixed $item, string $mode): int
    {
        return $mode === 'ship'
            ? max(0, (int) $item->quantity - (int) $item->dispatched_quantity)
            : max(0, (int) $item->inTransitQuantity());
    }

    /** @return array<int,int> */
    private function lineQuantities(array $data, string $mode): array
    {
        $quantities = [];
        $validKeys = [];

        foreach ($this->record->items()->get() as $item) {
            $key = 'quantity_'.$item->id;
            $validKeys[] = $key;
            $eligible = $this->eligibleQuantity($item, $mode);
            $raw = $data[$key] ?? 0;

            if (! is_numeric($raw) || (int) $raw < 0 || (int) $raw > $eligible || (float) $raw !== (float) (int) $raw) {
                throw ValidationException::withMessages([$key => 'Quantity must be a whole number within the eligible transfer balance.']);
            }

            if ((int) $raw > 0) {
                $quantities[$item->id] = (int) $raw;
            }
        }

        foreach (array_keys($data) as $key) {
            if (str_starts_with((string) $key, 'quantity_') && ! in_array($key, $validKeys, true)) {
                throw ValidationException::withMessages(['quantities' => 'Unknown transfer product quantity.']);
            }
        }

        if ($quantities === []) {
            throw ValidationException::withMessages(['quantities' => 'Enter a positive quantity for at least one product.']);
        }

        return $quantities;
    }

}
