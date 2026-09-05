<?php

namespace App\Filament\Resources\SalesConfigurations;

use App\Enums\ProductPermission;
use App\Enums\UpgradePermission;
use App\Filament\Resources\SalesConfigurations\Pages\CreateSalesConfiguration;
use App\Filament\Resources\SalesConfigurations\Pages\EditSalesConfiguration;
use App\Filament\Resources\SalesConfigurations\Pages\ListSalesConfigurations;
use App\Filament\Resources\UpgradeRecipes\UpgradeRecipeResource;
use App\Models\Product;
use App\Models\SalesConfiguration;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use App\Services\Upgrades\UpgradeRecipeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesConfigurationResource extends Resource
{
    protected static ?string $model = SalesConfiguration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Sales Configurations';

    public static function form(Schema $schema): Schema
    {
        $components = [
            Select::make('product_id')->label('Base Product')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Product::query()->select(['id', 'sku', 'name', 'inventory_item_type'])->products()->whereHas('hardwareProfile')->where(fn (Builder $query): Builder => $query->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} · {$product->name}"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($product = Product::query()->select(['id', 'sku', 'name'])->find($value)) ? "{$product->sku} · {$product->name}" : null)
                ->disabled(fn (?SalesConfiguration $record): bool => $record !== null),
            TextInput::make('display_name')->label('Configuration Name')->placeholder('16GB / 512GB')->required()->maxLength(255),
            TextInput::make('target_ram_mb')->label('Target RAM (MB)')->integer()->minValue(1),
            TextInput::make('target_storage_total_gb')->label('Target Storage (GB)')->numeric()->gt(0),
            Repeater::make('target_storage_layout')->label('Target Storage Layout')->columns(3)->columnSpanFull()->schema([
                TextInput::make('slot_key')->required()->maxLength(64),
                TextInput::make('capacity_gb')->label('Capacity (GB)')->numeric()->gt(0)->required(),
                TextInput::make('interface')->maxLength(120),
            ]),
            Toggle::make('active')->required()->default(true),
        ];

        if (self::sellingFieldsAllowed()) {
            $components[] = TextInput::make('suggested_selling_addon')->label('Suggested Selling Add-on')->prefix('AED')->numeric()->minValue(0)->default(0);
            $components[] = TextInput::make('default_selling_price')->label('Default Selling Price')->prefix('AED')->numeric()->minValue(0);
        }

        return $schema->components([Section::make('Customer Sales Configuration')->description('A configuration is a saleable design, not physical inventory.')->schema($components)->columns(2)]);
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('product.sku')->label('SKU')->searchable()->sortable(),
            TextColumn::make('product.name')->label('Base Product')->limit(45)->tooltip(fn (SalesConfiguration $record): string => $record->product->name),
            TextColumn::make('display_name')->label('Configuration')->searchable()->sortable(),
            TextColumn::make('target_ram_mb')->label('RAM')->formatStateUsing(fn ($state): string => $state ? round($state / 1024).'GB' : '—'),
            TextColumn::make('target_storage_total_gb')->label('Storage')->suffix(' GB')->placeholder('—'),
            TextColumn::make('recipes_count')->label('Recipes')->counts('recipes'),
            TextColumn::make('profile_state')->label('Profile State')->state(fn (SalesConfiguration $record): string => $record->isStale() ? 'Stale — revalidation required' : 'Current')->badge()->color(fn (string $state): string => str_starts_with($state, 'Stale') ? 'danger' : 'success'),
            IconColumn::make('active')->boolean(),
        ];
        if (self::sellingFieldsAllowed()) {
            $columns[] = TextColumn::make('suggested_selling_addon')->label('Add-on')->money('AED');
            $columns[] = TextColumn::make('default_selling_price')->label('Default Price')->money('AED')->placeholder('—');
        }

        return $table->columns($columns)->recordActions([
            Action::make('recipes')->label('Manage Recipes')->icon(Heroicon::OutlinedQueueList)->url(fn (SalesConfiguration $record): string => UpgradeRecipeResource::getUrl('index', ['configuration' => $record->id])),
            Action::make('revalidate')->label('Revalidate')->visible(fn (SalesConfiguration $record): bool => $record->isStale() && self::allowed(UpgradePermission::ManageRecipes))->requiresConfirmation()->action(function (SalesConfiguration $record): void {
                app(UpgradeRecipeService::class)->revalidateConfiguration($record, auth()->user());
                Notification::make()->success()->title('Configuration revalidated')->send();
            }),
            EditAction::make(),
        ])->defaultSort('id', 'desc')->emptyStateHeading('No Sales Configurations configured');
    }

    public static function getEloquentQuery(): Builder
    {
        $fields = ['id', 'product_id', 'hardware_profile_version', 'display_name', 'target_ram_mb', 'target_storage_total_gb', 'target_storage_layout', 'active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at'];
        if (self::sellingFieldsAllowed()) {
            array_push($fields, 'suggested_selling_addon', 'default_selling_price');
        }

        return parent::getEloquentQuery()->select($fields)->with(['product:id,sku,name', 'product.hardwareProfile:id,product_id,profile_version'])->withCount('recipes');
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesConfigurations::route('/'), 'create' => CreateSalesConfiguration::route('/create'), 'edit' => EditSalesConfiguration::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed(UpgradePermission::ManageConfigurations) || self::allowed(UpgradePermission::ManageRecipes);
    }

    public static function canCreate(): bool
    {
        return self::allowed(UpgradePermission::ManageConfigurations);
    }

    public static function canEdit($record): bool
    {
        return self::allowed(UpgradePermission::ManageConfigurations);
    }

    public static function allowed(UpgradePermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(UpgradeAuthorization::class)->allows($user, $permission);
    }

    private static function sellingFieldsAllowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && self::allowed(UpgradePermission::ManageSellingAddons)
            && app(ProductAuthorization::class)->allows($user, ProductPermission::EditSellingPrice);
    }
}
