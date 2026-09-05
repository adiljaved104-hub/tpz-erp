<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Enums\InventoryLocationType;
use App\Models\MarketplacePlatform;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->disabled(fn (?Warehouse $record): bool => $record?->code === 'MAIN')
                ->dehydrated(),
            TextInput::make('code')
                ->required()
                ->maxLength(50)
                ->regex('/^[A-Za-z0-9_-]+$/')
                ->unique(ignoreRecord: true)
                ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state)))
                ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state)))
                ->disabled(fn (?Warehouse $record): bool => $record?->code === 'MAIN')
                ->dehydrated()
                ->helperText('Uppercase letters, numbers, underscores, and hyphens only.'),
            Select::make('location_type')
                ->label('Location Type')
                ->options(InventoryLocationType::class)
                ->default(InventoryLocationType::CompanyWarehouse->value)
                ->required()
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set): void {
                    if (self::isMarketplaceFulfilment($state)) {
                        return;
                    }

                    $set('marketplace_platform_id', null);
                    $set('fulfillment_tag', null);
                })
                ->disabled(fn (?Warehouse $record): bool => $record?->code === 'MAIN'),
            Select::make('marketplace_platform_id')
                ->label('Marketplace Platform')
                ->options(fn (?Warehouse $record): array => MarketplacePlatform::query()
                    ->where(fn ($query) => $query->where('status', true)
                        ->when($record?->marketplace_platform_id, fn ($query, $id) => $query->orWhere('id', $id)))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(fn ($get): bool => self::isMarketplaceFulfilment($get('location_type')))
                ->visible(fn ($get): bool => self::isMarketplaceFulfilment($get('location_type'))),
            TextInput::make('fulfillment_tag')
                ->label('Fulfilment Tag')
                ->maxLength(50)
                ->visible(fn ($get): bool => self::isMarketplaceFulfilment($get('location_type'))),
            Textarea::make('address')->maxLength(2000)->rows(4)->columnSpanFull(),
        ]);
    }

    private static function isMarketplaceFulfilment(mixed $state): bool
    {
        return $state instanceof InventoryLocationType
            ? $state === InventoryLocationType::MarketplaceFulfilment
            : $state === InventoryLocationType::MarketplaceFulfilment->value;
    }
}
