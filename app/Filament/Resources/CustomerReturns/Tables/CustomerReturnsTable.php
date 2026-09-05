<?php

namespace App\Filament\Resources\CustomerReturns\Tables;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnRemovalStatus;
use App\Models\CustomerReturn;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\ReturnFinancialReadService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerReturnsTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('reference')->label('Return Ref')->searchable()->sortable(),
            TextColumn::make('order.reference')->label('Order'),
            TextColumn::make('platform.name')->label('Platform')->placeholder('—'),
            TextColumn::make('displayItems.product_name_snapshot')->label('Product')->limit(48)->tooltip(fn (CustomerReturn $record): string => $record->displayItems->pluck('product_name_snapshot')->join(', '))->limitList(1),
            TextColumn::make('displayItems.sku_snapshot')->label('SKU')->limitList(1)->toggleable(),
            TextColumn::make('displayItems.return_quantity')->label('Qty')->listWithLineBreaks(),
            TextColumn::make('refund.return_type')->label('Return Type')->placeholder('Customer Return')->badge()->toggleable(),
            TextColumn::make('refund.status')->label('Refund Status')->placeholder('Not Refunded')->badge(),
            TextColumn::make('refund.refund_date')->label('Refund Date')->date('d M Y')->placeholder('—')->toggleable(),
            TextColumn::make('refund.warrantyRepair.reference')->label('Warranty Ref')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('claims.status')->label('Claim Status')->badge()->placeholder('—')->toggleable(),
            TextColumn::make('receivingWarehouse.name')->label('Returned To')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status')->label('Physical Status')->badge(),
            TextColumn::make('reported_at')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
        ];

        if (self::canViewFinancials()) {
            $columns[] = TextColumn::make('refund.refund_amount')->label('Refund Amount')->money('AED', decimalPlaces: 2)->placeholder('—');
            $columns[] = TextColumn::make('claim_recovery')->label('Claim Recovery')
                ->state(fn (CustomerReturn $record): string => app(ReturnFinancialReadService::class)->paidRecovery($record, auth()->user()))
                ->money('AED', decimalPlaces: 2);
            $columns[] = TextColumn::make('net_refund_exposure')->label('Net Refund Exposure')
                ->state(fn (CustomerReturn $record): ?string => app(ReturnFinancialReadService::class)->netExposure($record, auth()->user()))
                ->money('AED', decimalPlaces: 2)->placeholder('—');
        }

        return $table->columns($columns)->filters([
            SelectFilter::make('operational_stage')->label('Queue')->options([
                'needs_disposition' => 'Needs Disposition',
                'awaiting_removal' => 'Awaiting Removal',
                'in_transit' => 'In Transit',
                'awaiting_qc' => 'Awaiting QC',
                'completed' => 'Completed',
            ])->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'needs_disposition' => $query->where('status', CustomerReturnStatus::Draft->value)
                        ->whereHas('fulfillmentWarehouse', fn (Builder $warehouse) => $warehouse->where('location_type', InventoryLocationType::MarketplaceFulfilment->value))
                        ->whereHas('items', fn (Builder $items) => $items->whereRaw('customer_return_items.return_quantity > (SELECT COALESCE(SUM(disposition.quantity), 0) FROM customer_return_marketplace_dispositions AS disposition WHERE disposition.customer_return_item_id = customer_return_items.id)')),
                    'awaiting_removal' => $query->whereHas('items', fn (Builder $items) => $items->whereRaw("(SELECT COALESCE(SUM(disposition.quantity), 0) FROM customer_return_marketplace_dispositions AS disposition WHERE disposition.customer_return_item_id = customer_return_items.id AND disposition.result = 'non_sellable') > (SELECT COALESCE(SUM(removal_item.quantity), 0) FROM marketplace_return_removal_items AS removal_item INNER JOIN marketplace_return_removals AS removal ON removal.id = removal_item.marketplace_return_removal_id WHERE removal_item.customer_return_item_id = customer_return_items.id AND removal.status <> 'cancelled')")),
                    'in_transit' => $query->whereHas('items.removalItems.removal', fn (Builder $removal) => $removal->where('status', MarketplaceReturnRemovalStatus::Dispatched->value)),
                    'awaiting_qc' => $query->where('status', CustomerReturnStatus::QcPending->value),
                    'completed' => $query->where('status', CustomerReturnStatus::Completed->value),
                    default => $query,
                };
            }),
            SelectFilter::make('status')->options(collect(CustomerReturnStatus::cases())->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all()),
        ])->recordActions([ViewAction::make()])
            ->emptyStateHeading('No Returns found for the selected filters.');
    }

    private static function canViewFinancials(): bool
    {
        return auth()->check() && app(CustomerReturnAuthorization::class)
            ->allows(auth()->user(), CustomerReturnPermission::ViewRefundAmount);
    }
}
