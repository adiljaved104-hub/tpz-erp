<?php

namespace App\Filament\Resources\OpeningStockEntries\Schemas;

use App\Models\Product;
use App\Models\Warehouse;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class OpeningStockEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Opening Stock')
                ->description('Available includes Reserved. Reserved Opening Stock is prohibited.')
                ->schema([
                    Select::make('product_id')->label('Product')->options(fn (): array => Product::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->name} — {$product->sku}"])->all())->searchable()->required(),
                    Select::make('warehouse_id')->label('Warehouse')->options(fn (): array => Warehouse::query()->active()->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('available_quantity')->label('Available Quantity')->numeric()->integer()->minValue(0)->default(0)->required(),
                    TextInput::make('damaged_quantity')->label('Damaged Quantity')->numeric()->integer()->minValue(0)->default(0)->required(),
                    TextInput::make('unit_cost')->label('Unit Cost')->prefix('AED')->required()->rule('regex:/^\d{1,11}(?:\.\d{1,4})?$/'),
                    Textarea::make('reason')->required()->maxLength(2000)->columnSpanFull(),
                    Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                ])->columns(2),
        ]);
    }
}
