<?php

namespace App\Filament\Resources\CustomerReturns\Schemas;

use App\Enums\CustomerReturnPermission;
use App\Models\CustomerReturn;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\ReturnFinancialReadService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerReturnInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $financial = auth()->check() && app(CustomerReturnAuthorization::class)
            ->allows(auth()->user(), CustomerReturnPermission::ViewRefundAmount);

        $refundEntries = [
            TextEntry::make('refund.return_type')->label('Return Type')->placeholder('Customer Return')->badge(),
            TextEntry::make('refund.status')->label('Refund Status')->placeholder('Not Refunded')->badge(),
            TextEntry::make('refund.refund_date')->label('Refund Date')->date('d M Y')->placeholder('—'),
            TextEntry::make('refund.warrantyRepair.reference')->label('Warranty Ref')->placeholder('—'),
        ];
        if ($financial) {
            $refundEntries[] = TextEntry::make('refund.refund_amount')->label('Refund Amount')->money('AED', decimalPlaces: 2)->placeholder('—');
            $refundEntries[] = TextEntry::make('claim_recovery')->label('Paid Claim Recovery')
                ->state(fn (CustomerReturn $record): string => app(ReturnFinancialReadService::class)->paidRecovery($record, auth()->user()))
                ->money('AED', decimalPlaces: 2);
            $refundEntries[] = TextEntry::make('net_refund_exposure')->label('Net Refund Exposure')
                ->state(fn (CustomerReturn $record): ?string => app(ReturnFinancialReadService::class)->netExposure($record, auth()->user()))
                ->money('AED', decimalPlaces: 2)->placeholder('—');
        }

        return $schema->components([
            Section::make('Customer Return')->columns(2)->schema([
                TextEntry::make('reference')->label('Return Reference'),
                TextEntry::make('order.reference')->label('Order'),
                TextEntry::make('platform.name')->label('Platform')->placeholder('—'),
                TextEntry::make('fulfillmentWarehouse.name')->label('Fulfilled From'),
                TextEntry::make('receivingWarehouse.name')->label('Returned To')->placeholder('Financial-only Return'),
                TextEntry::make('status')->label('Physical Status')->badge(),
                TextEntry::make('reported_at')->dateTime('d M Y, h:i A'),
                TextEntry::make('received_at')->dateTime('d M Y, h:i A')->placeholder('—'),
            ]),
            Section::make('Refund')->columns(3)->schema($refundEntries),
            Section::make('Related Claims')->schema([
                RepeatableEntry::make('claims')->hiddenLabel()->schema([
                    TextEntry::make('reference')->label('Claim Ref'),
                    TextEntry::make('status')->badge(),
                    ...($financial ? [
                        TextEntry::make('reimbursed_amount')->label('Paid Recovery')->money('AED', decimalPlaces: 2)->placeholder('—'),
                    ] : []),
                ])->columns($financial ? 3 : 2),
            ])->visible(fn (CustomerReturn $record): bool => $record->claims->isNotEmpty()),
            Section::make('Items')->schema([
                RepeatableEntry::make('displayItems')->hiddenLabel()->schema([
                    TextEntry::make('sku_snapshot')->label('SKU'),
                    TextEntry::make('product_name_snapshot')->label('Product'),
                    TextEntry::make('return_quantity')->label('Return Qty'),
                    TextEntry::make('return_reason')->label('Reason'),
                ])->table([
                    TableColumn::make('SKU'), TableColumn::make('Product'), TableColumn::make('Return Qty'), TableColumn::make('Reason'),
                ]),
            ]),
        ]);
    }
}
