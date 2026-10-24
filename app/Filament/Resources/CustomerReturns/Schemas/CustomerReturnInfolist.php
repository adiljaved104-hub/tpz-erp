<?php

namespace App\Filament\Resources\CustomerReturns\Schemas;

use App\Enums\CustomerReturnPermission;
use App\Enums\InventoryLocationType;
use App\Models\CustomerReturn;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\ReturnFinancialReadService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
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
            Section::make('QC Results & Evidence')->schema([
                ViewEntry::make('qc_results')->view('returns.qc-results')->hiddenLabel(),
                ViewEntry::make('return_evidence')->view('returns.evidence-gallery')->hiddenLabel(),
                TextEntry::make('marketplace_identity_hold')->label('Marketplace identity hold')
                    ->state('The expected item was not verified. Inventory and financial resolution is on hold until Owner/Admin approves a marketplace ledger rule.')
                    ->visible(fn (CustomerReturn $record): bool => $record->fulfillmentWarehouse?->location_type === InventoryLocationType::MarketplaceFulfilment
                        && $record->items()->whereHas('findings', fn ($query) => $query->whereIn('problem_type', ['wrong_item', 'serial_mismatch', 'empty_package']))->exists()),
            ]),
            Section::make('Wrong / Different Items Held for Verification')->schema([
                RepeatableEntry::make('quarantines')->hiddenLabel()->schema([
                    TextEntry::make('item.sku_snapshot')->label('Expected SKU'),
                    TextEntry::make('problem_type')->label('Problem'),
                    TextEntry::make('actual_product_name')->label('Actually received')->placeholder('Unidentified / empty package'),
                    TextEntry::make('warehouse.name')->label('Receiving Warehouse'),
                    TextEntry::make('actual_custody_quantity')->label('Actual Items Received'),
                    TextEntry::make('current_custody_quantity')->label('Items Currently Held')
                        ->state(fn ($record): ?int => $record->currentCustodyQuantity())->placeholder('Awaiting physical count'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('claim_case_reference')->label('Marketplace case')->placeholder('Not eligible / not opened'),
                    TextEntry::make('recordedBy.name')->label('Recorded by'),
                    TextEntry::make('evidence_readiness')->label('Evidence')->state(fn ($record): string => $record->evidenceReadiness()),
                    TextEntry::make('disposition')->label('Owner/Admin decision')->formatStateUsing(fn (?string $state): string => match ($state) {
                        'retain_in_quarantine' => 'Keep Item for Review',
                        'returned_to_sender' => 'Returned to Sender',
                        'empty_package_confirmed' => 'Empty Package Confirmed',
                        default => 'Waiting for review',
                    }),
                ])->columns(3),
            ])->visible(fn (CustomerReturn $record): bool => $record->quarantines()->exists()),
        ]);
    }
}
