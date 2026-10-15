<?php

namespace App\Filament\Pages\Qc;

use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Models\QcRecordedStockIntake;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcOrderDemandService;
use App\Services\Qc\QcRecordedRenewedStockIntakeService;
use App\Services\Qc\QcRecordedStockIntakeAdjustmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RecordedRenewedStockQueue extends Page implements HasTable
{
    use InteractsWithTable;

    private ?array $orderPriorityProjection = null;

    protected static ?string $slug = 'recorded-renewed-stock-awaiting-qc';

    protected static ?string $navigationLabel = 'Recorded Renewed Stock Awaiting QC';

    protected static ?string $title = 'Recorded Renewed Stock Awaiting QC';

    protected static string|\UnitEnum|null $navigationGroup = 'Quality Control';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.qc.recorded-renewed-stock-queue';

    public static function canAccess(): bool
    {
        return auth()->user() && app(QcRecordedRenewedStockIntakeService::class)->canAccess(auth()->user());
    }

    protected function getViewData(): array
    {
        return ['neededForOrders' => $this->orderPriorityProjection()['units_needed']];
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(QcRecordedRenewedStockIntakeService::class)->queueQuery(auth()->user()))
            ->columns([
                TextColumn::make('product.sku')->label('SKU')->searchable(),
                TextColumn::make('product.name')->label('Product')->wrap()->searchable(),
                TextColumn::make('warehouse.name')->label('Warehouse / Location'),
                TextColumn::make('receiptItem.receipt.reference')->label('GRN / Receipt'),
                TextColumn::make('receiptItem.receipt.received_at')->label('Received')->formatStateUsing(fn ($state): string => app(BusinessTimezone::class)->format($state)),
                TextColumn::make('accepted_quantity')->label('Recorded Intake Qty'),
                TextColumn::make('started_quantity')->label('QC Started'),
                TextColumn::make('completed_quantity')->label('QC Completed'),
                TextColumn::make('remaining')->label('Remaining')->state(fn (QcRecordedStockIntake $record): int => $record->accepted_quantity - $record->started_quantity)->badge(),
                TextColumn::make('order_priority_units')->label('Order Priority (SKU/location split)')
                    ->state(fn (QcRecordedStockIntake $record): int => $this->orderPriorityProjection()['by_intake_id'][$record->id] ?? 0)
                    ->tooltip('Order demand is aggregated by Product and Location, then split once across matching GRN rows.'),
            ])
            ->recordActions([
                Action::make('startQc')->label('Start QC')->icon('heroicon-o-play')
                    ->url(fn (QcRecordedStockIntake $record): string => QcInspectionResource::getUrl('create', ['recorded_stock_intake_id' => $record->id])),
                Action::make('adjustRecordedQcIntake')->label('Adjust Recorded QC Intake')->icon('heroicon-o-minus-circle')
                    ->visible(fn (): bool => app(QcRecordedStockIntakeAdjustmentService::class)->canAdjust(auth()->user()))
                    ->modalHeading('Adjust Recorded QC Intake')
                    ->modalDescription(fn (QcRecordedStockIntake $record): string => implode(' · ', [
                        'GRN: '.$record->receiptItem->receipt->reference,
                        'SKU: '.$record->product->sku,
                        'Location: '.$record->warehouse->name,
                        'Original GRN accepted: '.$record->receiptItem->accepted_quantity,
                        'Current intake: '.$record->accepted_quantity,
                        'QC already started: '.$record->started_quantity,
                        'Current remaining: '.max(0, $record->accepted_quantity - $record->started_quantity),
                        'This adjusts current Recorded QC Intake stock only. The original GRN remains unchanged for audit history.',
                    ]))
                    ->schema([
                        TextInput::make('adjustment_quantity')->label('Quantity to reduce')->integer()->required()->minValue(1)
                            ->maxValue(fn (QcRecordedStockIntake $record): int => max(0, $record->accepted_quantity - $record->started_quantity)),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(2000)->rows(3),
                        Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->uuid(),
                    ])
                    ->action(function (QcRecordedStockIntake $record, array $data): void {
                        app(QcRecordedStockIntakeAdjustmentService::class)->adjust($record->id, auth()->user(), $data);
                        Notification::make()->title('Recorded QC Intake adjusted')->success()->send();
                    }),
            ])
            ->defaultSort('id')->defaultPaginationPageOption(25);
    }

    /** @return array{units_needed: int, by_intake_id: array<int, int>} */
    private function orderPriorityProjection(): array
    {
        if ($this->orderPriorityProjection !== null) {
            return $this->orderPriorityProjection;
        }

        $actor = auth()->user();
        $demand = app(QcOrderDemandService::class)->calculateForQcQueue($actor);
        $intakes = app(QcRecordedRenewedStockIntakeService::class)->queueQuery($actor)->get();

        return $this->orderPriorityProjection = [
            'units_needed' => $demand['units_needed'],
            'by_intake_id' => app(QcRecordedRenewedStockIntakeService::class)
                ->distributeOrderPriority($intakes, $demand['by_product_location']),
        ];
    }
}
