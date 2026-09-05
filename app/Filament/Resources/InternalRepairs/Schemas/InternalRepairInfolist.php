<?php

namespace App\Filament\Resources\InternalRepairs\Schemas;

use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Models\WarrantyRepair;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InternalRepairInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Internal Repair')->columns(3)->schema([
                TextEntry::make('reference')->label('Repair Ref'),
                TextEntry::make('status')->badge(),
                TextEntry::make('source_type')->label('Repair Source')->state('Internal Repair / Damaged Items')->badge(),
                TextEntry::make('product.sku')->label('SKU'),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('quantity')->label('Qty'),
                TextEntry::make('warehouse.name')->label('Location'),
                TextEntry::make('damagedStockEvent.source')->label('Original Damage Source')
                    ->state(fn (WarrantyRepair $record): string => $record->isLegacyDamagedRepair() ? 'Old Damaged Stock' : ($record->damagedStockEvent?->source?->getLabel() ?? 'Unknown'))
                    ->badge(),
                TextEntry::make('damagedStockEvent.reference')->label('Damaged Ref')->placeholder('—')
                    ->helperText(fn (WarrantyRepair $record): ?string => $record->isLegacyDamagedRepair() ? 'Historical damage event was not recorded.' : null)
                    ->url(fn (WarrantyRepair $record): ?string => $record->damagedStockEvent?->reference ? DamagedItems::getUrl(['search' => $record->damagedStockEvent->reference]) : null),
                TextEntry::make('damagedStockEvent.customerReturn.reference')->label('Return')->placeholder('—')
                    ->url(fn (WarrantyRepair $record): ?string => $record->damagedStockEvent?->customerReturn ? CustomerReturnResource::getUrl('view', ['record' => $record->damagedStockEvent->customerReturn]) : null),
                TextEntry::make('damagedStockEvent.order.reference')->label('Order')->placeholder('—')
                    ->url(fn (WarrantyRepair $record): ?string => $record->damagedStockEvent?->order ? OrderResource::getUrl('view', ['record' => $record->damagedStockEvent->order]) : null),
                TextEntry::make('complaint.reference')->label('Complaint')->placeholder('—')
                    ->url(fn (WarrantyRepair $record): ?string => $record->complaint ? ComplaintResource::getUrl('view', ['record' => $record->complaint]) : null),
                TextEntry::make('damagedStockEvent.claim.reference')->label('Claim')->placeholder('—')
                    ->url(fn (WarrantyRepair $record): ?string => $record->damagedStockEvent?->claim ? SafetClaimResource::getUrl('view', ['record' => $record->damagedStockEvent->claim]) : null),
                TextEntry::make('platform.name')->label('Platform')->placeholder('Not applicable'),
                TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
                TextEntry::make('issue_description')->label('Repair Issue')->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Technician / Service Provider')->columns(3)->schema([
                TextEntry::make('service_provider')->label('Technician / Service Provider')->placeholder('—'),
                TextEntry::make('external_service_reference')->label('External Service Ref')->placeholder('—'),
                TextEntry::make('sent_to_technician_at')->label('Sent Date')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('expected_return_at')->label('Expected Return')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('repair_completed_at')->label('Repair Completed')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('received_back_at')->label('Received Back')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('qc_at')->label('QC Date')->dateTime('d M Y, h:i A')->placeholder('—'),
                TextEntry::make('completed_at')->label('Completed')->dateTime('d M Y, h:i A')->placeholder('—'),
            ]),
            Section::make('Status Timeline')->schema([
                RepeatableEntry::make('statusEvents')->label('')->schema([
                    TextEntry::make('to_status')->label('Status')->badge(),
                    TextEntry::make('changed_at')->label('Date / Time')->dateTime('d M Y, h:i A'),
                    TextEntry::make('changedBy.name')->label('Changed By'),
                    TextEntry::make('note')->label('Note / Reason')->placeholder('—'),
                ])->table([
                    TableColumn::make('Status'),
                    TableColumn::make('Date / Time'),
                    TableColumn::make('Changed By'),
                    TableColumn::make('Note / Reason'),
                ])->columnSpanFull(),
            ]),
        ]);
    }
}
