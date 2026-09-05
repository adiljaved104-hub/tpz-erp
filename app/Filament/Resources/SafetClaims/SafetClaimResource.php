<?php

namespace App\Filament\Resources\SafetClaims;

use App\Enums\SafetClaimPermission;
use App\Filament\Resources\SafetClaims\Pages\ListSafetClaims;
use App\Filament\Resources\SafetClaims\Pages\ViewSafetClaim;
use App\Filament\Resources\SafetClaims\Schemas\SafetClaimInfolist;
use App\Filament\Resources\SafetClaims\Tables\SafetClaimsTable;
use App\Models\SafetClaim;
use App\Models\User;
use App\Services\Authorization\SafetClaimAuthorization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SafetClaimResource extends Resource
{
    protected static ?string $model = SafetClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Returns';

    protected static ?string $navigationLabel = 'Claims';

    protected static ?string $modelLabel = 'Claim';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('safet_claim.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof SafetClaim && auth()->user()?->can('safet_claim.view', $record) === true;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return parent::getEloquentQuery()->whereRaw('1=0');
        }

        $fields = [
            'id', 'reference', 'marketplace_platform_id', 'customer_return_id', 'customer_return_item_id',
            'damaged_stock_event_id', 'order_id', 'order_item_id', 'order_fulfillment_item_id', 'product_id',
            'quantity', 'source', 'status', 'claim_reason', 'claim_program_name', 'external_claim_reference',
            'assigned_to_user_id', 'filing_due_at', 'filed_at', 'reviewed_at', 'approved_at', 'rejected_at',
            'paid_at', 'closed_at', 'notes', 'idempotency_key', 'created_by_user_id', 'created_at', 'updated_at',
        ];
        if (app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::ViewFinancial)) {
            array_push($fields, 'claimed_amount', 'approved_amount', 'reimbursed_amount', 'currency');
        }

        return app(SafetClaimAuthorization::class)->scopeQuery(parent::getEloquentQuery()->select($fields), $user)
            ->with(['platform:id,name', 'order:id,reference,warehouse_id,marketplace_platform_id', 'customerReturn:id,reference',
                'customerReturn.refund:id,customer_return_id,warranty_repair_id', 'customerReturn.refund.warrantyRepair:id,reference',
                'product:id,sku,name,brand_id', 'assignedTo:id,name', 'damagedStockEvent:id,reference,warehouse_id,occurred_at',
                'statusEvents.changedBy:id,name']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SafetClaimInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SafetClaimsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListSafetClaims::route('/'), 'view' => ViewSafetClaim::route('/{record}')];
    }
}
