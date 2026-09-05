<?php

namespace App\Filament\Resources\MarketplaceReturnRemovals;

use App\Filament\Resources\MarketplaceReturnRemovals\Pages\CreateMarketplaceReturnRemoval;
use App\Filament\Resources\MarketplaceReturnRemovals\Pages\ListMarketplaceReturnRemovals;
use App\Filament\Resources\MarketplaceReturnRemovals\Pages\ViewMarketplaceReturnRemoval;
use App\Filament\Resources\MarketplaceReturnRemovals\Schemas\MarketplaceReturnRemovalForm;
use App\Models\MarketplaceReturnRemoval;
use App\Services\Authorization\MarketplaceReturnAuthorization;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceReturnRemovalResource extends Resource
{
    protected static ?string $model = MarketplaceReturnRemoval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowRightStartOnRectangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Marketplace Removals';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        return app(MarketplaceReturnAuthorization::class)->scopeQuery(parent::getEloquentQuery(), auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return MarketplaceReturnRemovalForm::configure($schema);
    }

    public static function table(Table $t): Table
    {
        return $t->columns([TextColumn::make('reference'), TextColumn::make('platform.name')->label('Platform'), TextColumn::make('sourceWarehouse.name')->label('From'), TextColumn::make('destinationWarehouse.name')->label('To'), TextColumn::make('status')->badge(), TextColumn::make('requested_at')->dateTime('d M Y, h:i A')])->recordUrl(fn (MarketplaceReturnRemoval $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return ['index' => ListMarketplaceReturnRemovals::route('/'), 'create' => CreateMarketplaceReturnRemoval::route('/create'), 'view' => ViewMarketplaceReturnRemoval::route('/{record}')];
    }
}
