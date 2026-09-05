<?php

namespace App\Filament\Resources\Expenses;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseCostCenter;
use App\Enums\ExpensePermission;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\ExpenseService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Business Expenses (AED)';

    protected static ?string $modelLabel = 'business expense';

    protected static ?string $pluralModelLabel = 'Business Expenses (AED)';

    public static function getNavigationBadge(): ?string
    {
        return 'AED';
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'info';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Expense Details')
                ->description('Use this for UAE/Web Sales/Marketplace business expenses. Currency: AED.')
                ->schema([
                    DatePicker::make('expense_date')->label('Date')->default(today())->required(),
                    Select::make('category')->options(ExpenseCategory::options())->required()->searchable(),
                    TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
                    TextInput::make('amount')->label('Amount')->prefix('AED')->numeric()->minValue(0.01)->step(0.01)->required(),
                    Select::make('cost_center')->label('Cost Center / Business Area')->options(ExpenseCostCenter::options())->required(),
                    Select::make('employee_id')->label('Employee (optional)')->relationship('employee', 'name', fn (Builder $query) => $query->where('status', true))->searchable(),
                    TextInput::make('reference_note')->label('Reference / Note')->maxLength(500)->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $canAmount = self::allows(ExpensePermission::ViewAmount);

        return $table
            ->columns([
                TextColumn::make('expense_date')->label('Date')->date('d M Y')->sortable(),
                TextColumn::make('category')->badge()->formatStateUsing(fn (ExpenseCategory $state): string => $state->label())->sortable(),
                TextColumn::make('description')->searchable()->limit(45)->tooltip(fn (Expense $record): string => $record->description),
                TextColumn::make('cost_center')->label('Cost Center')->badge()->formatStateUsing(fn (ExpenseCostCenter $state): string => $state->label()),
                TextColumn::make('employee.name')->label('Employee')->placeholder('—'),
                TextColumn::make('amount')->label('Amount')->money('AED')->alignEnd()->visible($canAmount),
                TextColumn::make('voided_at')->label('Status')->badge()->formatStateUsing(fn ($state): string => $state ? 'Voided' : 'Active')->color(fn ($state): string => $state ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('category')->options(ExpenseCategory::options()),
                SelectFilter::make('cost_center')->options(ExpenseCostCenter::options()),
                TernaryFilter::make('voided')->queries(true: fn (Builder $query) => $query->whereNotNull('voided_at'), false: fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordActions([
                Action::make('void')
                    ->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                    ->visible(fn (Expense $record): bool => $record->voided_at === null && self::allows(ExpensePermission::DeleteOrVoid))
                    ->requiresConfirmation()
                    ->schema([TextInput::make('reason')->required()->minLength(5)->maxLength(500)])
                    ->action(function (Expense $record, array $data): void {
                        app(ExpenseService::class)->void($record, $data['reason'], auth()->user());
                        Notification::make()->success()->title('Expense voided')->send();
                    }),
            ])
            ->defaultSort('expense_date', 'desc')
            ->emptyStateHeading('No expenses recorded');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('employee');
        if (! self::allows(ExpensePermission::ViewAmount)) {
            $query->select(['id', 'expense_date', 'category', 'description', 'cost_center', 'employee_id', 'reference_note', 'created_by_user_id', 'updated_by_user_id', 'voided_at', 'voided_by_user_id', 'void_reason', 'created_at', 'updated_at']);
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        return self::allows(ExpensePermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return self::allows(ExpensePermission::Create) && self::allows(ExpensePermission::ViewAmount);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Expense && $record->voided_at === null && self::allows(ExpensePermission::Update) && self::allows(ExpensePermission::ViewAmount);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenses::route('/'), 'create' => CreateExpense::route('/create'), 'edit' => EditExpense::route('/{record}/edit')];
    }

    private static function allows(ExpensePermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ExpenseAuthorization::class)->allows($user, $permission);
    }
}
