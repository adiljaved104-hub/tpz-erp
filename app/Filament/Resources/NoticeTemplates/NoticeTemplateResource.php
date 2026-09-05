<?php

namespace App\Filament\Resources\NoticeTemplates;

use App\Enums\HrPermission;
use App\Filament\Resources\NoticeTemplates\Pages\CreateNoticeTemplate;
use App\Filament\Resources\NoticeTemplates\Pages\EditNoticeTemplate;
use App\Filament\Resources\NoticeTemplates\Pages\ListNoticeTemplates;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class NoticeTemplateResource extends Resource
{
    protected static ?string $model = NoticeTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Notice Templates';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Notice Template')->columns(2)->schema([
                Select::make('notice_category_id')->label('Category')->options(fn (?NoticeTemplate $record): array => self::categoryOptions($record))->searchable()->required(),
                TextInput::make('name')->label('Template Name')->required()->maxLength(255),
                TextInput::make('default_title')->label('Default Title')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('default_content')->label('Default Content')->required()->rows(7)->columnSpanFull(),
                Select::make('default_priority')->label('Default Priority')->options(['normal' => 'Normal', 'important' => 'Important'])->default('normal')->required(),
                Toggle::make('default_acknowledgment_required')->label('Default Acknowledgment Required')->default(false),
                Toggle::make('status')->label('Active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Template')->searchable()->sortable(),
            TextColumn::make('category.name')->label('Category')->sortable(),
            TextColumn::make('default_title')->label('Default Title')->limit(45),
            TextColumn::make('default_priority')->label('Priority')->badge(),
            IconColumn::make('default_acknowledgment_required')->label('Acknowledgment')->boolean(),
            IconColumn::make('status')->label('Active')->boolean(),
            TextColumn::make('notices_count')->label('Usage')->counts('notices'),
        ])->filters([
            SelectFilter::make('notice_category_id')->label('Category')->options(fn (): array => NoticeCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
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
            'index' => ListNoticeTemplates::route('/'),
            'create' => CreateNoticeTemplate::route('/create'),
            'edit' => EditNoticeTemplate::route('/{record}/edit'),
        ];
    }

    private static function manageAllowed(): bool
    {
        $user = auth()->user();

        return $user !== null && app(HrRecordAuthorization::class)->allows($user, HrPermission::NoticeManage);
    }

    private static function categoryOptions(?NoticeTemplate $record): array
    {
        return NoticeCategory::query()->where(function ($query) use ($record): void {
            $query->where('status', true);
            if ($record?->notice_category_id !== null) {
                $query->orWhere('id', $record->notice_category_id);
            }
        })->orderBy('name')->pluck('name', 'id')->all();
    }
}
