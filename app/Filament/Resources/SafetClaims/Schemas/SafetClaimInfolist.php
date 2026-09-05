<?php

namespace App\Filament\Resources\SafetClaims\Schemas;

use App\Enums\SafetClaimPermission;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\Authorization\SafetClaimAuthorization;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SafetClaimInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $financial = auth()->check() && app(SafetClaimAuthorization::class)
            ->allows(auth()->user(), SafetClaimPermission::ViewFinancial);
        $components = [
            Section::make('Claim')->columns(3)->schema([
                TextEntry::make('reference')->label('Claim Reference'), TextEntry::make('status')->badge(),
                TextEntry::make('platform.name')->label('Platform'), TextEntry::make('claim_program_name')->label('Claim Program')->placeholder('Not configured'),
                TextEntry::make('order.reference')->label('Order')->url(fn ($record) => OrderResource::getUrl('view', ['record' => $record->order_id])),
                TextEntry::make('customerReturn.reference')->label('Return')->url(fn ($record) => CustomerReturnResource::getUrl('view', ['record' => $record->customer_return_id])),
                TextEntry::make('customerReturn.refund.warrantyRepair.reference')->label('Warranty Ref')->placeholder('—'),
                TextEntry::make('product.sku')->label('SKU'), TextEntry::make('product.name')->label('Product'),
                TextEntry::make('quantity')->label('Qty'), TextEntry::make('claim_reason')->label('Damage Reason'),
                TextEntry::make('damagedStockEvent.occurred_at')->label('QC Date')->dateTime('d M Y, h:i A'),
                TextEntry::make('damagedStockEvent.reference')->label('Damaged Item')->url('/admin/damaged-items'),
                TextEntry::make('external_claim_reference')->label('External Claim Ref')->placeholder('Not filed'),
                TextEntry::make('assignedTo.name')->label('Assigned To')->placeholder('Unassigned'),
            ]),
        ];
        if ($financial) {
            $components[] = Section::make('Claim Financials')->columns(4)->schema([
                TextEntry::make('claimed_amount')->label('Claimed Amount')->money('AED', decimalPlaces: 2)->placeholder('—'),
                TextEntry::make('approved_amount')->label('Approved Amount')->money('AED', decimalPlaces: 2)->placeholder('—'),
                TextEntry::make('reimbursed_amount')->label('Paid / Reimbursed')->money('AED', decimalPlaces: 2)->placeholder('—'),
                TextEntry::make('paid_at')->label('Paid Date')->date('d M Y')->placeholder('—'),
                TextEntry::make('currency')->label('Currency')->placeholder('AED'),
            ]);
        }
        $components[] =
            Section::make('Timeline')->schema([
                RepeatableEntry::make('statusEvents')->hiddenLabel()->schema([
                    TextEntry::make('to_status')->label('Status')->badge(), TextEntry::make('changed_at')->label('Date')->dateTime('d M Y, h:i A'),
                    TextEntry::make('changedBy.name')->label('Changed By')->placeholder('System'), TextEntry::make('reason')->label('Note / Reason')->placeholder('—'),
                ])->columns(4),
            ]);

        return $schema->components($components);
    }
}
