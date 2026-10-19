<?php

namespace App\Filament\Resources\WarehouseStockVisibilityGrants;

use App\Enums\InventoryLocationType;
use App\Enums\ProductCondition;
use App\Enums\ResponsibilityPermission;
use App\Filament\Resources\WarehouseStockVisibilityGrants\Pages\CreateWarehouseStockVisibilityGrant;
use App\Filament\Resources\WarehouseStockVisibilityGrants\Pages\ListWarehouseStockVisibilityGrants;
use App\Models\Employee;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStockVisibilityGrant;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\WarehouseStockVisibilityGrantService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class WarehouseStockVisibilityGrantResource extends Resource
{
    protected static ?string $model = WarehouseStockVisibilityGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|\UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Shared Warehouse Visibility';

    protected static ?string $modelLabel = 'Warehouse Visibility Grant';

    protected static ?string $pluralModelLabel = 'Shared Warehouse Visibility';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', WarehouseStockVisibilityGrant::class) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', WarehouseStockVisibilityGrant::class) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['employee.team', 'warehouse', 'assignedBy', 'endedBy']);

        if (! $user instanceof User || ! $user->can('viewAny', WarehouseStockVisibilityGrant::class)) {
            return $query->whereRaw('1 = 0');
        }

        $authorization = app(ResponsibilityAuthorization::class);
        if ($authorization->allows($user, ResponsibilityPermission::ViewAll)) {
            return $query;
        }

        $employee = $user->employee;

        return $query->where(function (Builder $visible) use ($authorization, $employee, $user): void {
            if ($employee !== null && $authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
                $visible->orWhere('employee_id', $employee->id);
            }

            if ($employee?->team_id !== null && $authorization->allows($user, ResponsibilityPermission::ViewTeam)) {
                $visible->orWhereHas('employee', fn (Builder $employees) => $employees->where('team_id', $employee->team_id));
            }
        });
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shared read-only Stock by Location access')
                ->description('This grant only reveals physical stock rows and allocation-holder breakdowns in one selected warehouse and Product Condition. It does not transfer ownership or authorize adjustments, reservations, or stock actions.')
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])->schema([
                        Select::make('employee_id')->label('Employee')->required()->searchable()
                            ->options(fn (): array => Employee::query()->where('status', true)->whereNotNull('user_id')->orderBy('name')->get()
                                ->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->employee_id} · {$employee->name}"])->all()),
                        Select::make('warehouse_id')->label('Warehouse')->required()->searchable()
                            ->options(fn (): array => Warehouse::query()->where('status', true)
                                ->whereNotIn('location_type', [InventoryLocationType::MarketplaceFulfilment->value, InventoryLocationType::Transit->value])
                                ->orderBy('name')->get(['id', 'code', 'name'])
                                ->mapWithKeys(fn (Warehouse $warehouse): array => [$warehouse->id => "{$warehouse->code} — {$warehouse->name}"])->all()),
                        Select::make('product_condition')->label('Product Condition')->required()->searchable()
                            ->options(collect(ProductCondition::cases())->mapWithKeys(fn (ProductCondition $condition): array => [$condition->value => $condition->label()])->all()),
                        DateTimePicker::make('effective_at')->required()->default(now())->maxDate(now()),
                        Textarea::make('scope')->label('Scope validation')->disabled()->dehydrated(false)->rows(2)->columnSpanFull()
                            ->helperText('If this exact grant cannot be created, its validation explanation appears here.'),
                        Textarea::make('reason')->required()->maxLength(2000)->columnSpanFull(),
                        Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->uuid(),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee.employee_id')->label('Employee ID')->searchable()->sortable(),
            TextColumn::make('employee.name')->label('Employee')->searchable()->sortable(),
            TextColumn::make('warehouse.name')->label('Warehouse')->searchable()->sortable(),
            TextColumn::make('product_condition')->label('Condition')->formatStateUsing(fn (string $state): string => ProductCondition::tryFrom($state)?->label() ?? $state)->badge(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('effective_at')->dateTime('d M Y, h:i A')->sortable(),
            TextColumn::make('ended_at')->dateTime('d M Y, h:i A')->placeholder('Active')->sortable(),
            TextColumn::make('assignedBy.name')->label('Granted By')->toggleable(isToggledHiddenByDefault: true),
        ])->recordActions([
            Action::make('deactivate')
                ->label('Revoke visibility')
                ->color('danger')
                ->requiresConfirmation()
                ->authorize(fn (WarehouseStockVisibilityGrant $record): bool => auth()->user()->can('deactivate', $record))
                ->visible(fn (WarehouseStockVisibilityGrant $record): bool => $record->status === 'active')
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(fn (WarehouseStockVisibilityGrant $record, array $data) => app(WarehouseStockVisibilityGrantService::class)
                    ->deactivate($record, $data['reason'], auth()->user())),
        ])->toolbarActions([])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouseStockVisibilityGrants::route('/'),
            'create' => CreateWarehouseStockVisibilityGrant::route('/create'),
        ];
    }
}
