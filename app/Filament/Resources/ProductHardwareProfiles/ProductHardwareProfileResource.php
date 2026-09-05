<?php

namespace App\Filament\Resources\ProductHardwareProfiles;

use App\Enums\HardwareSubsystem;
use App\Enums\UpgradePermission;
use App\Filament\Resources\ProductHardwareProfiles\Pages\CreateProductHardwareProfile;
use App\Filament\Resources\ProductHardwareProfiles\Pages\EditProductHardwareProfile;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Component;
use App\Models\Product;
use App\Models\ProductHardwareProfile;
use App\Models\User;
use App\Services\Authorization\UpgradeAuthorization;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductHardwareProfileResource extends Resource
{
    protected static ?string $model = ProductHardwareProfile::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Hardware Profile';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Product Hardware Profile')->description('Operational upgrade facts. Existing Product RAM and storage text remains descriptive only.')->schema([
                Select::make('product_id')->label('Base Product')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Product::query()->select(['id', 'sku', 'name', 'inventory_item_type'])->products()->where(fn (Builder $query): Builder => $query->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} · {$product->name}"])->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($product = Product::query()->select(['id', 'sku', 'name'])->find($value)) ? "{$product->sku} · {$product->name}" : null)
                    ->default(fn (): ?int => request()->integer('product') ?: null)
                    ->disabled(fn (?ProductHardwareProfile $record): bool => $record !== null),
                Toggle::make('ram_upgradeable')->label('RAM Upgradeable')->required(),
                TextInput::make('max_supported_ram_mb')->label('Maximum Supported RAM (MB)')->integer()->minValue(1),
                Toggle::make('storage_upgradeable')->label('Storage Upgradeable')->required(),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
                Repeater::make('slots')->label('Hardware Slots')->minItems(1)->addActionLabel('Add Slot')->columns(4)->columnSpanFull()->schema([
                    Select::make('subsystem')->options(HardwareSubsystem::options())->required(),
                    TextInput::make('slot_key')->placeholder('RAM-1 / M2-1')->required()->maxLength(64),
                    TextInput::make('interface_type')->placeholder('DDR4 / NVMe')->maxLength(120),
                    Select::make('base_component_id')->label('Current Component')->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Component::query()->select(['id', 'product_id', 'specification'])->whereHas('product', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))->with('product:id,sku,name')->limit(30)->get()->mapWithKeys(fn (Component $component): array => [$component->id => "{$component->product->sku} · {$component->specification}"])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($component = Component::query()->select(['id', 'product_id', 'specification'])->with('product:id,sku')->find($value)) ? "{$component->product->sku} · {$component->specification}" : null),
                    Toggle::make('is_occupied')->label('Occupied')->required(),
                    Toggle::make('is_soldered')->label('Soldered')->required(),
                    TextInput::make('base_capacity_value')->label('Base Capacity')->numeric()->gt(0),
                    Select::make('base_capacity_unit')->options(['mb' => 'MB', 'gb' => 'GB', 'tb' => 'TB']),
                    TextInput::make('position')->integer()->minValue(0)->default(0),
                    TextInput::make('notes')->maxLength(1000)->columnSpan(3),
                ]),
            ])->columns(2),
        ]);
    }

    public static function getPages(): array
    {
        return ['create' => CreateProductHardwareProfile::route('/create'), 'edit' => EditProductHardwareProfile::route('/{record}/edit')];
    }

    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return ProductResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }

    public static function canCreate(): bool
    {
        return self::allowed();
    }

    public static function canEdit($record): bool
    {
        return self::allowed();
    }

    public static function canViewAny(): bool
    {
        return self::allowed();
    }

    private static function allowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(UpgradeAuthorization::class)->allows($user, UpgradePermission::ManageHardwareProfiles);
    }
}
