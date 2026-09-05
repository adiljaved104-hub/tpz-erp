<?php

namespace App\Filament\Resources\WarningCategories;

use App\Enums\HrPermission;
use App\Filament\Resources\WarningCategories\Pages\CreateWarningCategory;
use App\Filament\Resources\WarningCategories\Pages\EditWarningCategory;
use App\Filament\Resources\WarningCategories\Pages\ListWarningCategories;
use App\Models\WarningCategory;
use App\Services\Authorization\HrRecordAuthorization;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WarningCategoryResource extends Resource
{
    protected static ?string $model = WarningCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Warning Categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warning Category')->schema([
                TextInput::make('name')->required()->maxLength(255),
                Toggle::make('status')->label('Active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            IconColumn::make('status')->label('Active')->boolean(),
            TextColumn::make('warnings_count')->label('Warnings')->counts('warnings'),
        ])->recordActions([
            EditAction::make()->visible(fn (): bool => self::manageAllowed()),
        ]);
    }

    public static function canViewAny(): bool
    {
        return self::manageAllowed();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return self::manageAllowed();
    }

    public static function canEdit(Model $record): bool
    {
        return self::manageAllowed();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarningCategories::route('/'),
            'create' => CreateWarningCategory::route('/create'),
            'edit' => EditWarningCategory::route('/{record}/edit'),
        ];
    }

    private static function manageAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null && app(HrRecordAuthorization::class)->allows($user, HrPermission::WarningManage);
    }
}
