<?php

namespace App\Filament\Resources\StockMovements\Tables;

use App\Enums\InventoryPermission;
use App\Enums\StockMovementType;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('occurred_at')->label('Date')->dateTime('d M Y, h:i A')->sortable(),
            TextColumn::make('product.sku')->label('SKU')->searchable()->copyable(),
            TextColumn::make('product.name')->label('Product')->searchable()->limit(48)->wrap()
                ->tooltip(fn ($record): ?string => $record->product?->name),
            TextColumn::make('warehouse.name')->label('Warehouse'),
            TextColumn::make('movement_type')->badge()->formatStateUsing(fn (StockMovementType $state): string => $state->label()),
            TextColumn::make('quantity'),
            TextColumn::make('available_delta')->label('Available Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reserved_delta')->label('Reserved Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('damaged_delta')->label('Damaged Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('actor.name')->label('Actor')->toggleable(isToggledHiddenByDefault: true),
            ...(self::canViewFinancials() ? [
                TextColumn::make('unit_cost')->money('AED', decimalPlaces: 2)->placeholder('-'),
                TextColumn::make('average_cost_before')->money('AED', decimalPlaces: 2)->placeholder('-'),
                TextColumn::make('average_cost_after')->money('AED', decimalPlaces: 2)->placeholder('-'),
            ] : []),
        ])->filters([
            SelectFilter::make('movement_type')->options(collect(StockMovementType::cases())->mapWithKeys(fn (StockMovementType $type): array => [$type->value => $type->label()])->all()),
        ])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
