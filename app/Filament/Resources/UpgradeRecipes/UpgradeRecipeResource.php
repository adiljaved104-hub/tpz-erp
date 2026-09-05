<?php

namespace App\Filament\Resources\UpgradeRecipes;

use App\Enums\ComponentPermission;
use App\Enums\InventoryPermission;
use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradePermission;
use App\Enums\UpgradeRecipeOperation;
use App\Filament\Resources\UpgradeRecipes\Pages\CreateUpgradeRecipe;
use App\Filament\Resources\UpgradeRecipes\Pages\EditUpgradeRecipe;
use App\Filament\Resources\UpgradeRecipes\Pages\ListUpgradeRecipes;
use App\Models\Component;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use App\Services\Upgrades\UpgradeRecipeService;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UpgradeRecipeResource extends Resource
{
    protected static ?string $model = UpgradeRecipe::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'Upgrade Recipe';

    public static function form(Schema $schema): Schema
    {
        $lines = [
            TextInput::make('sequence')->integer()->minValue(1)->required(),
            Select::make('operation')->options(UpgradeRecipeOperation::options())->required(),
            TextInput::make('source_slot_key')->label('Source Slot')->maxLength(64),
            TextInput::make('target_slot_key')->label('Target Slot')->maxLength(64),
            Select::make('install_component_id')->label('Install Component')->searchable()->getSearchResultsUsing(fn (string $search): array => self::componentOptions($search))->getOptionLabelUsing(fn ($value): ?string => self::componentOptionLabel($value)),
            Select::make('recovered_component_id')->label('Recovered Component')->searchable()->getSearchResultsUsing(fn (string $search): array => self::componentOptions($search))->getOptionLabelUsing(fn ($value): ?string => self::componentOptionLabel($value)),
            TextInput::make('quantity_per_laptop')->numeric()->gt(0)->default(1)->required(),
            Select::make('recovery_valuation_method')->label('Recovery Valuation')->options(RecoveryValuationMethod::options())->default(RecoveryValuationMethod::NotApplicable->value)->required(),
        ];
        if (self::recoveryAllowed()) {
            $lines[] = TextInput::make('recovery_value_override')->label('Recovery Override')->prefix('AED')->numeric()->minValue(0);
            $lines[] = Textarea::make('override_reason')->label('Override Reason')->rows(2)->maxLength(1000);
        }

        $fields = [
            Select::make('sales_configuration_id')->label('Sales Configuration')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => SalesConfiguration::query()->select(['id', 'product_id', 'display_name'])->with('product:id,sku')->where(fn (Builder $query): Builder => $query->where('display_name', 'like', "%{$search}%")->orWhereHas('product', fn (Builder $product): Builder => $product->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->limit(30)->get()->mapWithKeys(fn (SalesConfiguration $configuration): array => [$configuration->id => "{$configuration->product->sku} · {$configuration->display_name}"])->all())
                ->getOptionLabelUsing(fn ($value): ?string => ($configuration = SalesConfiguration::query()->select(['id', 'product_id', 'display_name'])->with('product:id,sku')->find($value)) ? "{$configuration->product->sku} · {$configuration->display_name}" : null)
                ->default(fn (): ?int => request()->integer('configuration') ?: null)
                ->disabled(fn (?UpgradeRecipe $record): bool => $record !== null),
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('preferred')->label('Preferred Recipe')->default(false)->required(),
            TextInput::make('priority')->integer()->minValue(0)->default(100)->required(),
            Toggle::make('active')->default(true)->required(),
        ];
        if (self::costAllowed()) {
            $fields[] = TextInput::make('labour_unit_cost')->label('Internal Labour Unit Cost')->prefix('AED')->numeric()->minValue(0)->default(0);
        }
        $fields[] = Repeater::make('lines')->label('Recipe Steps')->minItems(1)->addActionLabel('Add Step')->columns(4)->columnSpanFull()->schema($lines);

        return $schema->components([Section::make('Upgrade Recipe Builder')->description('These steps validate the intended build only. No inventory is moved in Phase 1B.')->schema($fields)->columns(2)]);
    }

    public static function table(Table $table): Table
    {
        $columns = [
            TextColumn::make('salesConfiguration.product.sku')->label('SKU'),
            TextColumn::make('salesConfiguration.display_name')->label('Configuration'),
            TextColumn::make('name')->label('Recipe')->searchable(),
            IconColumn::make('preferred')->boolean(),
            TextColumn::make('priority')->sortable(),
            TextColumn::make('validation')->label('Validation')->state(fn (UpgradeRecipe $record): string => app(UpgradeRecipeValidationService::class)->validate($record)->valid ? 'Valid' : 'Invalid')->badge()->color(fn (string $state): string => $state === 'Valid' ? 'success' : 'danger'),
            TextColumn::make('build_summary')->label('Build Summary')->state(fn (UpgradeRecipe $record): string => app(UpgradeRecipeValidationService::class)->validate($record)->buildSummary)->wrap()->limit(80)->tooltip(fn (UpgradeRecipe $record): string => app(UpgradeRecipeValidationService::class)->validate($record)->buildSummary),
            IconColumn::make('active')->boolean(),
        ];
        if (self::costAllowed()) {
            $columns[] = TextColumn::make('labour_unit_cost')->label('Labour Cost')->money('AED');
        }
        if (self::recoveryAllowed()) {
            $columns[] = TextColumn::make('recovery_summary')->label('Recovery Valuation')->state(function (UpgradeRecipe $record): string {
                $values = $record->lines->filter(fn ($line): bool => $line->recovery_valuation_method !== RecoveryValuationMethod::NotApplicable)->map(function ($line): string {
                    $value = app(UpgradeRecipeService::class)->resolvedRecoveryValue($line);

                    return $line->recovery_valuation_method === RecoveryValuationMethod::Override
                        ? "Override: AED {$value} · {$line->override_reason}"
                        : "Standard Recovery: AED {$value}";
                });

                return $values->isEmpty() ? '—' : $values->implode('; ');
            })->wrap();
        }

        return $table->columns($columns)->recordActions([
            Action::make('preferred')->label('Mark Preferred')->visible(fn (UpgradeRecipe $record): bool => ! $record->preferred)->requiresConfirmation()->action(function (UpgradeRecipe $record): void {
                app(UpgradeRecipeService::class)->markPreferred($record, auth()->user());
                Notification::make()->success()->title('Preferred recipe updated')->send();
            }),
            EditAction::make(),
        ])->modifyQueryUsing(fn (Builder $query): Builder => $query->when(request()->integer('configuration'), fn (Builder $query, int $id): Builder => $query->where('sales_configuration_id', $id)))->defaultSort('preferred', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $fields = ['id', 'sales_configuration_id', 'hardware_profile_version', 'name', 'preferred', 'priority', 'active', 'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at'];
        if (self::costAllowed()) {
            $fields[] = 'labour_unit_cost';
        }
        $lineFields = ['id', 'upgrade_recipe_id', 'sequence', 'operation', 'source_slot_key', 'target_slot_key', 'install_component_id', 'recovered_component_id', 'quantity_per_laptop', 'recovery_valuation_method'];
        if (self::recoveryAllowed()) {
            array_push($lineFields, 'recovery_value_override', 'override_reason', 'recovery_approved_by_user_id', 'recovery_approved_at');
        }

        $componentFields = 'id,product_id,component_type,specification,capacity_value,capacity_unit,interface_type';
        if (self::recoveryAllowed()) {
            $componentFields .= ',approved_oem_recovery_value';
        }

        return parent::getEloquentQuery()->select($fields)->with([
            'salesConfiguration:id,product_id,hardware_profile_version,display_name,target_ram_mb,target_storage_total_gb,target_storage_layout',
            'salesConfiguration.product:id,sku,name',
            'salesConfiguration.product.hardwareProfile:id,product_id,profile_version,ram_upgradeable,max_supported_ram_mb,storage_upgradeable',
            'salesConfiguration.product.hardwareProfile.slots:id,product_hardware_profile_id,subsystem,slot_key,interface_type,is_soldered,is_occupied,base_component_id,base_capacity_value,base_capacity_unit,position',
            "salesConfiguration.product.hardwareProfile.slots.baseComponent:{$componentFields}",
            'salesConfiguration.product.hardwareProfile.slots.baseComponent.product:id,status',
            'lines' => fn ($query) => $query->select($lineFields),
            "lines.installComponent:{$componentFields}",
            'lines.installComponent.product:id,status',
            "lines.recoveredComponent:{$componentFields}",
            'lines.recoveredComponent.product:id,status',
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListUpgradeRecipes::route('/'), 'create' => CreateUpgradeRecipe::route('/create'), 'edit' => EditUpgradeRecipe::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed(UpgradePermission::ManageRecipes);
    }

    public static function canCreate(): bool
    {
        return self::allowed(UpgradePermission::ManageRecipes);
    }

    public static function canEdit($record): bool
    {
        return self::allowed(UpgradePermission::ManageRecipes);
    }

    public static function recoveryAllowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ComponentAuthorization::class)->allows($user, ComponentPermission::ViewRecoveryValue);
    }

    public static function costAllowed(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
    }

    private static function allowed(UpgradePermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(UpgradeAuthorization::class)->allows($user, $permission);
    }

    private static function componentOptions(string $search): array
    {
        return Component::query()->select(['id', 'product_id', 'specification'])->whereHas('product', fn (Builder $query): Builder => $query->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->with('product:id,sku,name')->limit(30)->get()->mapWithKeys(fn (Component $component): array => [$component->id => "{$component->product->sku} · {$component->specification}"])->all();
    }

    private static function componentOptionLabel(mixed $value): ?string
    {
        $component = Component::query()->select(['id', 'product_id', 'specification'])->with('product:id,sku')->find($value);

        return $component ? "{$component->product->sku} · {$component->specification}" : null;
    }
}
