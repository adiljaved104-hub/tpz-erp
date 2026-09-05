<?php

namespace App\Filament\Resources\NoticeCategories;

use App\Enums\HrPermission;
use App\Filament\Resources\NoticeCategories\Pages\CreateNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\EditNoticeCategory;
use App\Filament\Resources\NoticeCategories\Pages\ListNoticeCategories;
use App\Models\NoticeCategory;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NoticeCategoryResource extends Resource
{
    protected static ?string $model = NoticeCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Notice Categories';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Notice Category')->schema([
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
            TextColumn::make('notices_count')->label('Notices')->counts('notices'),
        ])->filters([
            TernaryFilter::make('status')->label('Active'),
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
            'index' => ListNoticeCategories::route('/'),
            'create' => CreateNoticeCategory::route('/create'),
            'edit' => EditNoticeCategory::route('/{record}/edit'),
        ];
    }

    private static function manageAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null && app(HrRecordAuthorization::class)->allows($user, HrPermission::NoticeManage);
    }
}
