<?php

namespace App\Filament\Resources\MarketplacePlatforms;

use App\Filament\Resources\MarketplacePlatforms\Pages\CreateMarketplacePlatform;
use App\Filament\Resources\MarketplacePlatforms\Pages\EditMarketplacePlatform;
use App\Filament\Resources\MarketplacePlatforms\Pages\ListMarketplacePlatforms;
use App\Filament\Resources\MarketplacePlatforms\Pages\ViewMarketplacePlatform;
use App\Filament\Resources\MarketplacePlatforms\Schemas\MarketplacePlatformForm;
use App\Filament\Resources\MarketplacePlatforms\Schemas\MarketplacePlatformInfolist;
use App\Filament\Resources\MarketplacePlatforms\Tables\MarketplacePlatformsTable;
use App\Models\MarketplacePlatform;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MarketplacePlatformResource extends Resource
{
    protected static ?string $model = MarketplacePlatform::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Platforms';

    protected static ?string $modelLabel = 'Platform';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return MarketplacePlatformForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MarketplacePlatformInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MarketplacePlatformsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketplacePlatforms::route('/'),
            'create' => CreateMarketplacePlatform::route('/create'),
            'view' => ViewMarketplacePlatform::route('/{record}'),
            'edit' => EditMarketplacePlatform::route('/{record}/edit'),
        ];
    }
}
