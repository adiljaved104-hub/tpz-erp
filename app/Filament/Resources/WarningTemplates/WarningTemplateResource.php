<?php

namespace App\Filament\Resources\WarningTemplates;

use App\Enums\HrPermission;
use App\Filament\Resources\WarningTemplates\Pages\CreateWarningTemplate;
use App\Filament\Resources\WarningTemplates\Pages\EditWarningTemplate;
use App\Filament\Resources\WarningTemplates\Pages\ListWarningTemplates;
use App\Models\WarningCategory;
use App\Models\WarningTemplate;
use App\Services\Authorization\HrRecordAuthorization;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

class WarningTemplateResource extends Resource
{
    protected static ?string $model = WarningTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Warning Templates';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warning Template')->columns(2)->schema([
                Select::make('warning_category_id')->label('Warning Category')->options(fn (): array => WarningCategory::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                TextInput::make('name')->label('Template Name')->required()->maxLength(255),
                TextInput::make('default_title')->label('Default Title')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('default_description')->label('Default Details')->required()->rows(6)->columnSpanFull(),
                Select::make('default_warning_level')->label('Default Warning Type')->options(['verbal' => 'Verbal', 'written' => 'Written', 'final' => 'Final'])->required(),
                Toggle::make('default_acknowledgment_required')->label('Default Acknowledgment Required')->default(true),
                Toggle::make('status')->label('Active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Template')->searchable()->sortable(),
            TextColumn::make('category.name')->label('Warning Category'),
            TextColumn::make('default_warning_level')->label('Default Type')->badge(),
            IconColumn::make('default_acknowledgment_required')->label('Acknowledgment')->boolean(),
            IconColumn::make('status')->label('Active')->boolean(),
            TextColumn::make('warnings_count')->label('Usage')->counts('warnings'),
        ])->filters([TernaryFilter::make('status')->label('Active')])->recordActions([
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
            'index' => ListWarningTemplates::route('/'),
            'create' => CreateWarningTemplate::route('/create'),
            'edit' => EditWarningTemplate::route('/{record}/edit'),
        ];
    }

    private static function manageAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null && app(HrRecordAuthorization::class)->allows($user, HrPermission::WarningManage);
    }
}
