<?php

namespace App\Filament\Resources\StockTransfers\Tables;

use App\Enums\StockTransferStatus;
use App\Actions\StockTransfers\DispatchStockTransfer;
use App\Actions\StockTransfers\ReceiveStockTransfer;
use App\Actions\StockTransfers\CancelStockTransfer;
use App\Exceptions\InventoryInvariantException;
use App\Exceptions\InvalidStockTransferTransitionException;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\StockTransfer;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockTransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable()->url(fn (StockTransfer $record): string => StockTransferResource::getUrl('view', ['record' => $record])), TextColumn::make('sourceWarehouse.name')->label('From'), TextColumn::make('destinationWarehouse.name')->label('To'), TextColumn::make('status')->badge(),
            TextColumn::make('transfer_date')->date('d M Y')->sortable(), TextColumn::make('displayItems')->label('Products / Qty')->state(fn ($record): string => $record->displayItems->map(fn ($item): string => "{$item->sku} × {$item->quantity}")->join(', '))->limit(64)->tooltip(fn (StockTransfer $record): string => $record->displayItems->map(fn ($item): string => "{$item->sku} × {$item->quantity}")->join(', ')), TextColumn::make('handledBy.name')->label('Handled By')->placeholder('—'),
        ])->filters([SelectFilter::make('status')->options(collect(StockTransferStatus::cases())->mapWithKeys(fn ($status): array => [$status->value => $status->getLabel()])->all())])->recordActions([
                ViewAction::make(),
                Action::make('ship')
                    ->label('Ship')->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::Draft && auth()->user()?->can('dispatch', $record) === true)
                    ->action(fn (StockTransfer $record) => self::runAction(
                        'Cannot ship transfer',
                        fn () => app(DispatchStockTransfer::class)->handle($record, (string) Str::uuid(), auth()->user()),
                    )),
                Action::make('receive')
                    ->label('Receive')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::Dispatched && auth()->user()?->can('receive', $record) === true)
                    ->action(fn (StockTransfer $record) => self::runAction(
                        'Cannot receive transfer',
                        fn () => app(ReceiveStockTransfer::class)->handle($record, (string) Str::uuid(), auth()->user()),
                    )),
                Action::make('cancel')
                    ->label('Cancel Draft')->color('danger')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                    ->visible(fn (StockTransfer $record): bool => $record->status === StockTransferStatus::Draft && auth()->user()?->can('cancel', $record) === true)
                    ->action(fn (StockTransfer $record, array $data) => self::runAction(
                        'Cannot cancel transfer',
                        fn () => app(CancelStockTransfer::class)->handle($record, (string) $data['reason'], (string) Str::uuid(), auth()->user()),
                    )),
            ])
            ->emptyStateHeading('No Stock Transfers found for the selected filters.');
    }

    /** @param callable(): mixed $operation */
    private static function runAction(string $title, callable $operation): void
    {
        try {
            $operation();
            Notification::make()->success()->title('Transfer updated')->send();
        } catch (InventoryInvariantException|InvalidStockTransferTransitionException $exception) {
            Notification::make()->danger()->title($title)->body($exception->getMessage())->send();

            throw new Halt;
        }
    }

}
