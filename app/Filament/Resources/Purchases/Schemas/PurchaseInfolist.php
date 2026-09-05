<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Enums\PurchaseEntryType;
use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PurchaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $financial = self::allowed(PurchasePermission::ViewFinancials, $schema->getRecord());

        return $schema->components([
            Section::make('Purchase')->schema([
                TextEntry::make('reference'), TextEntry::make('status')->badge(),
                TextEntry::make('entry_type')->label('Entry Type')->badge()->formatStateUsing(fn (PurchaseEntryType $state): string => $state->getLabel())->color(fn (PurchaseEntryType $state): string => $state->getColor()),
                TextEntry::make('supplier.name')->label('Supplier')->placeholder('No Supplier'),
                TextEntry::make('warehouse.name'), TextEntry::make('supplier_invoice_number'), TextEntry::make('purchase_date')->date(),
                TextEntry::make('handledBy.name')->label('Handled By / Reported By')->placeholder('Not specified'),
                TextEntry::make('external_accounting_reference')->label('External Accounting Reference'),
                ...($financial ? [TextEntry::make('subtotal')->money('AED'), TextEntry::make('discount_total')->money('AED'), TextEntry::make('vat_total')->money('AED'), TextEntry::make('grand_total')->money('AED')] : []),
                TextEntry::make('notes')->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    private static function allowed(PurchasePermission $permission, mixed $purchase = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, $permission, $purchase instanceof Purchase ? $purchase : null);
    }
}
