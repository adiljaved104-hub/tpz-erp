<?php

namespace App\Filament\Resources\MarketplacePlatforms\Schemas;

use App\Enums\InventoryLocationType;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketplacePlatformForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Marketplace Platform')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')
                    ->rules(fn (string $operation): array => $operation === 'create'
                        ? ['required', 'max:100', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/']
                        : [])
                    ->helperText('Lowercase letters, numbers, and underscores. Code is immutable after creation.')
                    ->disabledOn('edit'),
                Select::make('return_handling_mode')
                    ->label('Return Handling Mode')
                    ->options(MarketplaceReturnHandlingMode::class)
                    ->nullable()
                    ->native(false)
                    ->helperText('Required later before marketplace Return processing can begin.'),
                Select::make('default_return_receiving_warehouse_id')
                    ->label('Default Return Receiving Location')
                    ->options(fn (): array => Warehouse::query()
                        ->active()
                        ->where('location_type', '!=', InventoryLocationType::Transit->value)
                        ->orderByRaw('CASE WHEN location_type = ? THEN 0 ELSE 1 END', [InventoryLocationType::CompanyWarehouse->value])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->nullable()
                    ->helperText('Optional suggestion only; it never moves inventory.'),
                Toggle::make('customer_return_claims_enabled')->label('Customer Return Claims Enabled')->default(false)->live(),
                TextInput::make('claim_program_name')->label('Claim Program')->maxLength(255)
                    ->placeholder('Safe-T')->visible(fn ($get): bool => (bool) $get('customer_return_claims_enabled')),
            ])->columns(2),
        ]);
    }
}
