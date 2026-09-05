<?php

namespace App\Filament\Resources\Components;

use App\Enums\ComponentPermission;
use App\Enums\ComponentType;
use App\Enums\InventoryPermission;
use App\Enums\ProductStatus;
use App\Filament\Resources\Components\Pages\CreateComponent;
use App\Filament\Resources\Components\Pages\EditComponent;
use App\Filament\Resources\Components\Pages\ListComponents;
use App\Filament\Resources\Components\Pages\ViewComponent;
use App\Models\Component;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Components\ComponentCatalogService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ComponentResource extends Resource
{
    protected static ?string $model = Component::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Upgrade Components';

    protected static ?string $modelLabel = 'Upgrade Component';

    protected static ?string $pluralModelLabel = 'Upgrade Components';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Component Catalog')
                ->description('Components are real inventory items. Purchases and receipts use the existing weighted-average inventory ledger.')
                ->schema([
                    TextInput::make('sku')->disabled()->dehydrated(false)->placeholder('Auto Generated'),
                    TextInput::make('name')->required()->maxLength(255),
                    Select::make('brand_id')->label('Brand')->options(fn (?Component $record): array => ProductBrand::query()->where(fn (Builder $query): Builder => $query
                        ->where('status', true)->when($record?->product?->brand_id, fn (Builder $query, int $id): Builder => $query->orWhereKey($id)))
                        ->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    Select::make('category_id')->label('Category')->options(fn (?Component $record): array => ProductCategory::query()->where(fn (Builder $query): Builder => $query
                        ->where('status', true)->when($record?->product?->category_id, fn (Builder $query, int $id): Builder => $query->orWhereKey($id)))
                        ->orderBy('name')->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('model')->maxLength(255),
                    Select::make('component_type')->options(ComponentType::options())->required(),
                    TextInput::make('specification')->placeholder('e.g. 8GB DDR4 3200')->required()->maxLength(255),
                    TextInput::make('capacity_value')->numeric()->gt(0),
                    Select::make('capacity_unit')->options(['mb' => 'MB', 'gb' => 'GB', 'tb' => 'TB', 'mah' => 'mAh', 'other' => 'Other']),
                    TextInput::make('interface_type')->placeholder('e.g. DDR4, NVMe PCIe 4.0')->maxLength(120),
                    Textarea::make('description')->rows(4)->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $canInventoryCost = self::inventoryCostAllowed();
        $canRecovery = self::allowed(ComponentPermission::ViewRecoveryValue);

        $columns = [
            TextColumn::make('product.sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('product.name')->label('Component')->searchable()->limit(45)->tooltip(fn (Component $record): string => $record->product->name),
            TextColumn::make('component_type')->label('Type')->badge()->formatStateUsing(fn (ComponentType $state): string => $state->label()),
            TextColumn::make('specification')->searchable()->wrap(),
            TextColumn::make('available_quantity')->label('Available')->numeric(decimalPlaces: 0)->sortable(),
            TextColumn::make('reserved_quantity')->label('Reserved')->numeric(decimalPlaces: 0)->sortable(),
            TextColumn::make('damaged_quantity')->label('Damaged')->numeric(decimalPlaces: 0)->sortable(),
        ];

        if ($canInventoryCost) {
            $columns[] = TextColumn::make('component_average_cost')->label('Average Cost')->money('AED');
        }

        if ($canRecovery) {
            $columns[] = TextColumn::make('approved_oem_recovery_value')->label('Approved OEM Recovery')->money('AED');
        }

        $columns[] = TextColumn::make('product.status')->label('Status')->badge()->formatStateUsing(fn (ProductStatus $state): string => $state->label())->color(fn (ProductStatus $state): string => $state->color());

        $recordActions = [
            ViewAction::make(),
            EditAction::make(),
        ];

        if (self::allowed(ComponentPermission::ApproveRecoveryValue)) {
            $recordActions[] = Action::make('approveRecovery')
                ->label('Set OEM Recovery Value')->icon(Heroicon::OutlinedCurrencyDollar)
                ->visible(fn (Component $record): bool => self::allowed(ComponentPermission::ApproveRecoveryValue, $record))
                ->schema([
                    TextInput::make('value')->label('Approved OEM Recovery Value')->prefix('AED')->numeric()->minValue(0)->step(0.0001)->required(),
                    Textarea::make('reason')->required()->minLength(5)->maxLength(1000),
                ])
                ->action(function (Component $record, array $data): void {
                    app(ComponentCatalogService::class)->updateApprovedRecoveryValue($record, (string) $data['value'], $data['reason'], auth()->user());
                    Notification::make()->success()->title('OEM recovery value approved')->send();
                });
        }

        $recordActions[] = Action::make('changeStatus')
            ->label(fn (Component $record): string => $record->product->status === ProductStatus::Active ? 'Deactivate' : 'Activate')
            ->color(fn (Component $record): string => $record->product->status === ProductStatus::Active ? 'danger' : 'success')
            ->visible(fn (Component $record): bool => self::allowed(ComponentPermission::ChangeStatus, $record))
            ->requiresConfirmation()->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(1000)])
            ->action(function (Component $record, array $data): void {
                $status = $record->product->status === ProductStatus::Active ? ProductStatus::Inactive : ProductStatus::Active;
                app(ComponentCatalogService::class)->changeStatus($record, $status, $data['reason'], auth()->user());
                Notification::make()->success()->title('Component status updated')->send();
            });

        return $table->columns($columns)->filters([
            SelectFilter::make('component_type')->options(ComponentType::options()),
            SelectFilter::make('status')
                ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->label()])->all())
                ->query(fn (Builder $query, array $data): Builder => $query->when(
                    $data['value'] ?? null,
                    fn (Builder $query, string $status): Builder => $query->whereHas('product', fn (Builder $product): Builder => $product->where('status', $status)),
                )),
        ])->recordActions($recordActions)->defaultSort('id', 'desc')->emptyStateHeading('No upgrade components configured');
    }

    public static function getEloquentQuery(): Builder
    {
        $fields = ['id', 'product_id', 'component_type', 'specification', 'capacity_value', 'capacity_unit', 'interface_type', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at'];
        if (self::allowed(ComponentPermission::ViewRecoveryValue)) {
            array_push($fields, 'approved_oem_recovery_value', 'recovery_approved_by_user_id', 'recovery_approved_at', 'recovery_reason');
        }

        $query = parent::getEloquentQuery()->select($fields)
            ->with(['product:id,sku,inventory_item_type,name,brand,brand_id,category,category_id,model,description,status'])
            ->withSum('inventories as available_quantity', 'available_quantity')
            ->withSum('inventories as reserved_quantity', 'reserved_quantity')
            ->withSum('inventories as damaged_quantity', 'damaged_quantity');

        if (self::inventoryCostAllowed()) {
            $query->selectSub(
                DB::table('product_inventories as component_cost_inventory')
                    ->selectRaw('CASE WHEN SUM(available_quantity + damaged_quantity) = 0 THEN 0 ELSE SUM((available_quantity + damaged_quantity) * average_cost) / SUM(available_quantity + damaged_quantity) END')
                    ->whereColumn('component_cost_inventory.product_id', 'components.product_id'),
                'component_average_cost',
            );
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListComponents::route('/'),
            'create' => CreateComponent::route('/create'),
            'view' => ViewComponent::route('/{record}'),
            'edit' => EditComponent::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return self::allowed(ComponentPermission::View);
    }

    public static function canCreate(): bool
    {
        return self::allowed(ComponentPermission::Create);
    }

    private static function allowed(ComponentPermission $permission, ?Component $component = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ComponentAuthorization::class)->allows($user, $permission, $component);
    }

    private static function inventoryCostAllowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }
}
