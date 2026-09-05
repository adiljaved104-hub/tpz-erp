<?php

namespace App\Filament\Resources\EmployeeWarnings;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\WarningLevel;
use App\Filament\Resources\EmployeeWarnings\Pages\CreateEmployeeWarning;
use App\Filament\Resources\EmployeeWarnings\Pages\ListEmployeeWarnings;
use App\Filament\Resources\EmployeeWarnings\Pages\ViewEmployeeWarning;
use App\Models\Employee;
use App\Models\EmployeeWarning;
use App\Models\WarningCategory;
use App\Services\Authorization\HrRecordAuthorization;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EmployeeWarningResource extends Resource
{
    protected static ?string $model = EmployeeWarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Warnings';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Issue Warning')->columns(2)->schema([
                Select::make('employee_id')->label('Employee')->options(fn (): array => self::employeeOptions())->searchable()->required(),
                Select::make('warning_level')->label('Warning Type')->options(WarningLevel::class)->required(),
                Select::make('warning_category_id')->label('Category')->options(fn (): array => WarningCategory::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                DatePicker::make('issued_date')->label('Issue Date')->default(today())->maxDate(today())->required(),
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('Details')->required()->rows(5)->columnSpanFull(),
                Toggle::make('acknowledgment_required')->label('Acknowledgment Required')->default(true),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Warning')->columns(3)->schema([
                TextEntry::make('reference')->label('Warning Ref'),
                TextEntry::make('warning_level')->label('Type')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('employee.name')->label('Employee'),
                TextEntry::make('category.name')->label('Category'),
                TextEntry::make('issued_date')->label('Issued')->date('d M Y'),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('description')->label('Details')->columnSpanFull(),
                TextEntry::make('issuedBy.name')->label('Issued By'),
                TextEntry::make('acknowledgment_required')->label('Acknowledgment Required')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No'),
                TextEntry::make('read_state')->label('Read State')->state(fn (EmployeeWarning $record): string => $record->acknowledgments->first()?->read_at ? 'Read' : 'Unread')->badge(),
                TextEntry::make('acknowledgment_state')->label('Acknowledgment')->state(fn (EmployeeWarning $record): string => $record->acknowledgments->first()?->acknowledged_at ? 'Acknowledged' : ($record->acknowledgment_required ? 'Pending' : 'Not Required'))->badge(),
                TextEntry::make('acknowledged_at')->label('Acknowledged At')->state(fn (EmployeeWarning $record) => $record->acknowledgments->first()?->acknowledged_at)->dateTime('d M Y, h:i A')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('Warning Ref')->searchable()->sortable(),
            TextColumn::make('employee.name')->label('Employee')->searchable(),
            TextColumn::make('warning_level')->label('Type')->badge(),
            TextColumn::make('category.name')->label('Category'),
            TextColumn::make('title')->limit(40)->tooltip(fn (EmployeeWarning $record): string => $record->title),
            TextColumn::make('issued_date')->label('Issued')->date('d M Y')->sortable(),
            IconColumn::make('acknowledged')->label('Acknowledged')->boolean()->state(fn (EmployeeWarning $record): bool => $record->acknowledgments->contains(fn ($ack): bool => $ack->acknowledged_at !== null)),
            TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('warning_level')->label('Type')->options(WarningLevel::class),
            SelectFilter::make('status')->options(['active' => 'Active', 'closed' => 'Closed']),
        ])->recordActions([ViewAction::make()]);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            HrPermission::WarningViewOwn,
            HrPermission::WarningViewTeam,
            HrPermission::WarningViewAll,
            HrPermission::WarningIssue,
        ])->contains(fn (HrPermission $permission): bool => self::authorization()->allows($user, $permission));
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        return $user !== null && collect([
            HrPermission::WarningViewOwn,
            HrPermission::WarningViewTeam,
            HrPermission::WarningViewAll,
        ])->contains(fn (HrPermission $permission): bool => self::authorization()->allows($user, $permission));
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user !== null && self::authorization()->allows($user, HrPermission::WarningIssue);
    }

    public static function canView(Model $record): bool
    {
        return auth()->user() !== null && self::authorization()->canViewWarning(auth()->user(), $record);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['employee:id,name,team_id', 'category:id,name', 'issuedBy:id,name', 'acknowledgments']);

        return $user ? self::authorization()->scopeWarnings($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return EmployeeWarning::query();
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeWarnings::route('/'), 'create' => CreateEmployeeWarning::route('/create'), 'view' => ViewEmployeeWarning::route('/{record}')];
    }

    private static function authorization(): HrRecordAuthorization
    {
        return app(HrRecordAuthorization::class);
    }

    private static function employeeOptions(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }
        $query = Employee::query()->where('status', true);
        if (! in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            $query->where('team_id', $user->employee?->team_id);
        }

        return $query->orderBy('name')->get(['id', 'employee_id', 'name'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->employee_id} · {$employee->name}"])->all();
    }
}
