<?php

namespace App\Filament\Resources\Purchases\Tables;

use App\Actions\Purchases\ExportPurchases;
use App\DTOs\Purchases\PurchaseExportData;
use App\Enums\PurchaseEntryType;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('PO Ref')->searchable()->sortable(), TextColumn::make('supplier.name')->label('Supplier')->placeholder('No Supplier')->searchable(),
            TextColumn::make('warehouse.code')->label('Location'), TextColumn::make('purchase_date')->label('Purchased')->date('d M Y')->sortable(),
            TextColumn::make('expected_delivery_date')->label('Expected')->date('d M Y')->sortable()->toggleable(), TextColumn::make('status')->badge(),
            TextColumn::make('entry_type')->label('Entry Type')->badge()->formatStateUsing(fn (PurchaseEntryType $state): string => $state->getLabel())->color(fn (PurchaseEntryType $state): string => $state->getColor()),
            ...(self::allowed(PurchasePermission::ViewFinancials) ? [TextColumn::make('grand_total')->money('AED')->sortable()] : []),
        ])->filters([
            SelectFilter::make('status')->options(collect(PurchaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all()),
            SelectFilter::make('entry_type')->label('Entry Type')->options(collect(PurchaseEntryType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->getLabel()])->all()),
        ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([Action::make('export')->label('Export CSV')->visible(fn (): bool => self::allowed(PurchasePermission::Export))->authorize(fn (): bool => self::allowed(PurchasePermission::Export))->action(fn () => app(ExportPurchases::class)->handle(new PurchaseExportData, auth()->user()))])
            ->emptyStateHeading('No Purchases found for the selected filters.');
    }

    private static function allowed(PurchasePermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, $permission);
    }
}
