<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ComponentPermission;
use App\Enums\InventoryPermission;
use App\Enums\ProductPermission;
use App\Enums\UpgradePermission;
use App\Models\Product;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use App\Services\Upgrades\HardwareProfileService;
use App\Services\Upgrades\SalesConfigurationService;
use App\Services\Upgrades\UpgradeRecipeService;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductIntelligenceController extends MobileController
{
    public function show(Request $request, int $product): JsonResponse
    {
        $product = $this->product($request, $product)->load([
            'hardwareProfile.slots.baseComponent.product',
            'salesConfigurations.recipes.lines.installComponent.product',
            'salesConfigurations.recipes.lines.recoveredComponent.product',
            'marketplaceListings.platform', 'marketplaceListings.account',
            'marketplaceListings.observations' => fn ($query) => $query->latest('observed_at')->limit(10),
        ]);
        $user = $request->user();
        $upgrades = app(UpgradeAuthorization::class);
        $selling = $upgrades->allows($user, UpgradePermission::ManageSellingAddons)
            && app(ProductAuthorization::class)->allows($user, ProductPermission::EditSellingPrice, $product);
        $cost = app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewFinancials);
        $recovery = app(ComponentAuthorization::class)->allows($user, ComponentPermission::ViewRecoveryValue);

        return response()->json(['data' => [
            'id' => $product->id,
            'title' => $product->name,
            'sku' => $product->sku,
            'hardware_profile' => $product->hardwareProfile === null ? null : [
                'id' => $product->hardwareProfile->id,
                'version' => $product->hardwareProfile->profile_version,
                'ram_upgradeable' => $product->hardwareProfile->ram_upgradeable,
                'max_supported_ram_mb' => $product->hardwareProfile->max_supported_ram_mb,
                'storage_upgradeable' => $product->hardwareProfile->storage_upgradeable,
                'notes' => $product->hardwareProfile->notes,
                'slots' => $product->hardwareProfile->slots->map(fn ($slot): array => [
                    'subsystem' => $slot->subsystem->value,
                    'slot_key' => $slot->slot_key,
                    'interface_type' => $slot->interface_type,
                    'is_soldered' => $slot->is_soldered,
                    'is_occupied' => $slot->is_occupied,
                    'base_component_id' => $slot->base_component_id,
                    'base_component' => $slot->baseComponent?->product?->name,
                    'base_capacity_value' => $slot->base_capacity_value,
                    'base_capacity_unit' => $slot->base_capacity_unit,
                    'base_capacity' => collect([$slot->base_capacity_value, $slot->base_capacity_unit])->filter()->implode(' '),
                    'position' => $slot->position,
                    'notes' => $slot->notes,
                ])->values(),
            ],
            'configurations' => $product->salesConfigurations->map(fn (SalesConfiguration $configuration): array => [
                'id' => $configuration->id,
                'display_name' => $configuration->display_name,
                'target_ram_mb' => $configuration->target_ram_mb,
                'target_storage_total_gb' => $configuration->target_storage_total_gb,
                'target_storage_layout' => $configuration->target_storage_layout,
                'active' => $configuration->active,
                'stale' => $configuration->isStale(),
                ...($selling ? [
                    'suggested_selling_addon' => $configuration->suggested_selling_addon,
                    'default_selling_price' => $configuration->default_selling_price,
                ] : []),
                'recipes' => $configuration->recipes->map(function (UpgradeRecipe $recipe) use ($cost, $recovery): array {
                    $validation = app(UpgradeRecipeValidationService::class)->validate($recipe);

                    return [
                        'id' => $recipe->id,
                        'name' => $recipe->name,
                        'preferred' => $recipe->preferred,
                        'priority' => $recipe->priority,
                        'active' => $recipe->active,
                        'valid' => $validation->valid,
                        'validation_errors' => $validation->errors,
                        'build_summary' => $validation->buildSummary,
                        ...($cost ? ['labour_unit_cost' => $recipe->labour_unit_cost] : []),
                        'lines' => $recipe->lines->map(fn ($line): array => [
                            'sequence' => $line->sequence,
                            'operation' => $line->operation->value,
                            'source_slot_key' => $line->source_slot_key,
                            'target_slot_key' => $line->target_slot_key,
                            'install_component' => $line->installComponent?->product?->name,
                            'recovered_component' => $line->recoveredComponent?->product?->name,
                            'quantity_per_laptop' => $line->quantity_per_laptop,
                            'recovery_valuation_method' => $line->recovery_valuation_method->value,
                            ...($recovery ? [
                                'recovery_value' => app(UpgradeRecipeService::class)->resolvedRecoveryValue($line),
                                'override_reason' => $line->override_reason,
                            ] : []),
                        ])->values(),
                    ];
                })->values(),
            ])->values(),
            'marketplace_listings' => $product->marketplaceListings->map(fn ($listing): array => [
                'id' => $listing->id,
                'platform' => $listing->platform?->name,
                'account' => $listing->account?->name,
                'marketplace_identifier' => $listing->marketplace_identifier,
                'listing_sku' => $listing->listing_sku,
                'listing_title' => $listing->listing_title,
                'monitor_enabled' => $listing->monitor_enabled,
                'last_checked_at' => $listing->last_checked_at?->format('Y-m-d H:i'),
                'last_successful_observation_at' => $listing->last_successful_observation_at?->format('Y-m-d H:i'),
                'listing_active_state' => $listing->listing_active_state,
                'featured_offer_state' => $listing->featured_offer_state,
                'last_check_error' => $listing->last_check_error,
                'observations' => $listing->observations->map(fn ($observation): array => [
                    'observed_at' => $observation->observed_at?->format('Y-m-d H:i'),
                    'source_status' => $observation->source_status,
                    'listing_active_state' => $observation->listing_active_state,
                    'featured_offer_state' => $observation->featured_offer_state,
                    'error' => $observation->safe_error,
                ])->values(),
            ])->values(),
            'capabilities' => [
                'manage_hardware_profile' => $upgrades->allows($user, UpgradePermission::ManageHardwareProfiles),
                'manage_configurations' => $upgrades->allows($user, UpgradePermission::ManageConfigurations),
                'manage_recipes' => $upgrades->allows($user, UpgradePermission::ManageRecipes),
                'manage_selling_addons' => $selling,
            ],
        ]]);
    }

    public function saveProfile(Request $request, int $product): JsonResponse
    {
        app(HardwareProfileService::class)->save($this->product($request, $product), $request->all(), $request->user());

        return $this->show($request, $product);
    }

    public function storeConfiguration(Request $request, int $product): JsonResponse
    {
        $product = $this->product($request, $product);
        app(SalesConfigurationService::class)->create([...$request->all(), 'product_id' => $product->id], $request->user());

        return $this->show($request, $product->id);
    }

    public function updateConfiguration(Request $request, int $product, int $configuration): JsonResponse
    {
        $product = $this->product($request, $product);
        $configuration = SalesConfiguration::query()->where('product_id', $product->id)->findOrFail($configuration);
        app(SalesConfigurationService::class)->update($configuration, $request->all(), $request->user());

        return $this->show($request, $product->id);
    }

    public function revalidateConfiguration(Request $request, int $product, int $configuration): JsonResponse
    {
        $product = $this->product($request, $product);
        $configuration = SalesConfiguration::query()->where('product_id', $product->id)->findOrFail($configuration);
        app(UpgradeRecipeService::class)->revalidateConfiguration($configuration, $request->user());

        return $this->show($request, $product->id);
    }

    public function preferRecipe(Request $request, int $product, int $recipe): JsonResponse
    {
        $product = $this->product($request, $product);
        $recipe = UpgradeRecipe::query()->whereHas('salesConfiguration', fn ($query) => $query->where('product_id', $product->id))->findOrFail($recipe);
        app(UpgradeRecipeService::class)->markPreferred($recipe, $request->user());

        return $this->show($request, $product->id);
    }

    private function product(Request $request, int $product): Product
    {
        $product = Product::query()->products()->findOrFail($product);
        app(ProductAuthorization::class)->authorize($request->user(), ProductPermission::View, $product);

        return $product;
    }
}
