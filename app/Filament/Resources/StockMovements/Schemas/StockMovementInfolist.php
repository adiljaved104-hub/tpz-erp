<?php

namespace App\Filament\Resources\StockMovements\Schemas;

use App\Enums\InventoryPermission;
use App\Enums\StockMovementType;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class StockMovementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Movement')
                ->schema([
                    TextEntry::make('reference'),
                    TextEntry::make('occurred_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('product.name')->label('Product'),
                    TextEntry::make('product.sku')->label('SKU'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('movement_type')->formatStateUsing(fn (StockMovementType $state): string => $state->label())->badge(),
                    TextEntry::make('quantity'),
                    TextEntry::make('available_delta')->label('Available Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state)),
                    TextEntry::make('reserved_delta')->label('Reserved Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state)),
                    TextEntry::make('damaged_delta')->label('Damaged Change')->formatStateUsing(fn (int $state): string => sprintf('%+d', $state)),
                    TextEntry::make('reason')->columnSpanFull(),
                    TextEntry::make('actor.name')->label('Actor'),
                    ...(self::canViewFinancials() ? [
                        TextEntry::make('unit_cost')->money('AED', decimalPlaces: 2)->placeholder('-'),
                        TextEntry::make('average_cost_before')->money('AED', decimalPlaces: 2)->placeholder('-'),
                        TextEntry::make('average_cost_after')->money('AED', decimalPlaces: 2)->placeholder('-'),
                    ] : []),
                ])->columns(4),
        ]);
    }

    private static function canViewFinancials(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
