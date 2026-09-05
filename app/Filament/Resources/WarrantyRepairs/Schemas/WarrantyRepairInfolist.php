<?php

namespace App\Filament\Resources\WarrantyRepairs\Schemas;

use App\Enums\CustomerReturnPermission;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\ReturnFinancialReadService;
use App\Services\ServiceCases\WarrantySlaService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WarrantyRepairInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $financial = auth()->check() && app(CustomerReturnAuthorization::class)
            ->allows(auth()->user(), CustomerReturnPermission::ViewRefundAmount);
        $refundEntries = [
            TextEntry::make('customer_refunded')->label('Customer Refunded')
                ->state(fn (WarrantyRepair $record): string => ($record->refund ?? $record->customerReturn?->refund) === null ? 'No' : 'Yes')->badge(),
            TextEntry::make('customerReturn.reference')->label('Return Ref')->placeholder('—'),
            TextEntry::make('refund_date')->label('Refund Date')
                ->state(fn (WarrantyRepair $record) => ($record->refund ?? $record->customerReturn?->refund)?->refund_date)
                ->date('d M Y')->placeholder('—'),
            TextEntry::make('customerReturn.claims.reference')->label('Claim Ref')->placeholder('—'),
            TextEntry::make('customerReturn.claims.status')->label('Claim Status')->badge()->placeholder('—'),
        ];
        if ($financial) {
            $refundEntries[] = TextEntry::make('refund_amount')->label('Refund Amount')
                ->state(fn (WarrantyRepair $record) => ($record->refund ?? $record->customerReturn?->refund)?->refund_amount)
                ->money('AED', decimalPlaces: 2)->placeholder('—');
            $refundEntries[] = TextEntry::make('claim_recovery')->label('Paid Claim Recovery')
                ->state(fn (WarrantyRepair $record): ?string => $record->customerReturn === null ? null : app(ReturnFinancialReadService::class)->paidRecovery($record->customerReturn, auth()->user()))
                ->money('AED', decimalPlaces: 2)->placeholder('—');
        }

        return $schema->components([
            Section::make('Main Case Details')->columns(3)->schema([
                TextEntry::make('reference')->label('Internal Ref'),
                TextEntry::make('status')->badge(),
                TextEntry::make('platform.name')->label('Platform')->placeholder('—'),
                TextEntry::make('order.reference')->label('Order Reference')->placeholder('—'),
                TextEntry::make('order.external_order_number')->label('Marketplace Order ID')->placeholder('—'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('product.sku')->label('SKU'),
                TextEntry::make('quantity'),
                TextEntry::make('serial_number')->label('Serial Number')->placeholder('—'),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('source')->badge(),
                TextEntry::make('received_from')->label('Received From')->placeholder('—'),
                TextEntry::make('received_at')->label('Received At')->dateTime('d M Y, h:i:s A'),
                TextEntry::make('sla_due')->label('SLA Due')->state(fn (WarrantyRepair $record) => app(WarrantySlaService::class)->dueAt($record))->dateTime('d M Y, h:i:s A'),
                TextEntry::make('sla_status')->label('SLA Status')->state(fn (WarrantyRepair $record): string => app(WarrantySlaService::class)->status($record))->badge(),
                TextEntry::make('sla_days_left')->label('Days Left')->state(fn (WarrantyRepair $record): string => app(WarrantySlaService::class)->daysLeftLabel($record)),
                TextEntry::make('expected_return_at')->label('Expected Return')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                TextEntry::make('issue_description')->label('Issue Description')->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Refund / Claim')->columns(3)->schema($refundEntries)
                ->visible(fn (WarrantyRepair $record): bool => ! $record->isInternalCompanyOwnedRepair()
                    && (($record->refund ?? $record->customerReturn?->refund) !== null || $record->customerReturn?->claims?->isNotEmpty() === true)),
            Section::make('Service Details')->columns(3)->schema([
                TextEntry::make('service_provider')->label('Technician / Service Provider')->placeholder('—'),
                TextEntry::make('external_service_reference')->label('External Item / Service Ref')->placeholder('—'),
                TextEntry::make('sent_to_technician_at')->label('Sent to Technician')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('received_back_at')->label('Received Back')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('qc_at')->label('QC Date')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('inspection_result')->label('QC / Inspection Result')->placeholder('—'),
                TextEntry::make('dispatched_back_at')->label('Dispatch Date')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('dispatch_tracking_reference')->label('Tracking / Dispatch Reference')->placeholder('—'),
                TextEntry::make('completed_at')->label('Completed')->dateTime('d M Y, h:i A')->placeholder('—'),
            ]),
            Section::make('Damaged Items')->columns(3)->schema([
                TextEntry::make('damaged_state')->label('State')->state(fn (WarrantyRepair $record): string => $record->moved_to_damaged_at === null ? 'Not moved' : 'Moved to Damaged')->badge(),
                TextEntry::make('moved_to_damaged_quantity')->label('Moved Quantity')->placeholder('—'),
                TextEntry::make('movedToDamagedEvent.warehouse.name')->label('Destination Location')->placeholder('—'),
                TextEntry::make('moved_to_damaged_at')->label('Moved Date')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('movedToDamagedBy.name')->label('Moved By')->placeholder('—'),
                TextEntry::make('movedToDamagedEvent.reference')->label('Damaged Item Reference')->placeholder('—'),
            ]),
            Section::make('Status Timeline')->schema([
                RepeatableEntry::make('statusEvents')
                    ->label('')
                    ->schema([
                        TextEntry::make('to_status')->label('Status')->badge(),
                        TextEntry::make('changed_at')->label('Date / Time')->dateTime('d M Y, h:i A'),
                        TextEntry::make('changedBy.name')->label('Changed By'),
                        TextEntry::make('note')->label('Note / Reason')->placeholder('—'),
                    ])
                    ->table([
                        TableColumn::make('Status'),
                        TableColumn::make('Date / Time'),
                        TableColumn::make('Changed By'),
                        TableColumn::make('Note / Reason'),
                    ])
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
