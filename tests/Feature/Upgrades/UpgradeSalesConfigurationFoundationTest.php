<?php

namespace Tests\Feature\Upgrades;

use App\Enums\ComponentType;
use App\Enums\EmployeeRole;
use App\Enums\HardwareSubsystem;
use App\Enums\ProductStatus;
use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradePermission;
use App\Enums\UpgradeRecipeOperation;
use App\Filament\Resources\ProductHardwareProfiles\ProductHardwareProfileResource;
use App\Filament\Resources\SalesConfigurations\SalesConfigurationResource;
use App\Filament\Resources\UpgradeRecipes\UpgradeRecipeResource;
use App\Models\Component;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductHardwareProfile;
use App\Models\SalesConfiguration;
use App\Models\StockMovement;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\Authorization\AccessControlModuleRegistry;
use App\Services\Authorization\UpgradeAuthorization;
use App\Services\Upgrades\HardwareProfileService;
use App\Services\Upgrades\UpgradeRecipeService;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpgradeSalesConfigurationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_is_additive_and_creates_no_business_rows_or_inventory_movements(): void
    {
        $this->assertTrue(Schema::hasColumns('product_hardware_profiles', ['product_id', 'profile_version', 'ram_upgradeable', 'storage_upgradeable']));
        $this->assertTrue(Schema::hasColumns('product_hardware_slots', ['subsystem', 'slot_key', 'base_component_id']));
        $this->assertTrue(Schema::hasColumns('sales_configurations', ['product_id', 'hardware_profile_version', 'target_storage_layout']));
        $this->assertTrue(Schema::hasColumns('upgrade_recipes', ['sales_configuration_id', 'preferred', 'labour_unit_cost']));
        $this->assertTrue(Schema::hasColumns('upgrade_recipe_lines', ['operation', 'recovery_valuation_method', 'recovery_value_override']));
        $this->assertDatabaseCount('product_hardware_profiles', 0);
        $this->assertDatabaseCount('sales_configurations', 0);
        $this->assertDatabaseCount('upgrade_recipes', 0);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_no_profile_is_supported_but_a_recipe_cannot_guess_hardware(): void
    {
        $recipe = $this->recipe(Product::factory()->create(), ['target_ram_mb' => 8192], []);
        $result = app(UpgradeRecipeValidationService::class)->validate($recipe);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('No Hardware Profile', $result->errors[0]);
    }

    public function test_ram_single_double_soldered_and_soldered_only_cases(): void
    {
        $ram8 = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4');
        $ram16 = $this->fixtureComponent(ComponentType::Ram, 16, 'gb', 'DDR4');

        $double = $this->productWithProfile([
            $this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8),
            $this->slot('RAM-2', HardwareSubsystem::Ram, false, false, 'DDR4'),
        ], maxRam: 32768);
        $result = $this->validateRecipe($double, 16384, null, [
            $this->line(1, UpgradeRecipeOperation::Keep, source: 'RAM-1'),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'RAM-2', install: $ram8),
        ]);
        $this->assertTrue($result->valid, implode(' | ', $result->errors));
        $this->assertSame(['ram_total' => 2, 'ram_occupied' => 1, 'ram_free' => 1, 'storage_total' => 0, 'storage_occupied' => 0, 'storage_free' => 0], $double->hardwareProfile->derivedSlotCounts());

        $single = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8)], maxRam: 32768);
        $result = $this->validateRecipe($single, 16384, null, [
            $this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'RAM-1', recovered: $ram8, recovery: RecoveryValuationMethod::CentralApproved),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'RAM-1', install: $ram16),
        ]);
        $this->assertTrue($result->valid, implode(' | ', $result->errors));

        $solderedSlot = $this->productWithProfile([
            $this->slot('RAM-SOLDERED', HardwareSubsystem::Ram, true, true, 'DDR4', $ram8),
            $this->slot('RAM-1', HardwareSubsystem::Ram, false, false, 'DDR4'),
        ], maxRam: 16384);
        $result = $this->validateRecipe($solderedSlot, 16384, null, [
            $this->line(1, UpgradeRecipeOperation::Keep, source: 'RAM-SOLDERED'),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'RAM-1', install: $ram8),
        ]);
        $this->assertTrue($result->valid, implode(' | ', $result->errors));

        $solderedOnly = $this->productWithProfile([$this->slot('RAM-SOLDERED', HardwareSubsystem::Ram, true, true, 'DDR4', $ram8)], ramUpgradeable: false, maxRam: 8192);
        $result = $this->validateRecipe($solderedOnly, 16384, null, [$this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'RAM-SOLDERED', recovered: $ram8, recovery: RecoveryValuationMethod::CentralApproved)]);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('soldered', implode(' ', $result->errors));
    }

    public function test_ssd_replace_second_slot_interface_and_missing_facts_cases(): void
    {
        $ssd256 = $this->fixtureComponent(ComponentType::Ssd, 256, 'gb', 'NVMe');
        $ssd512 = $this->fixtureComponent(ComponentType::Ssd, 512, 'gb', 'NVMe');
        $sata512 = $this->fixtureComponent(ComponentType::Ssd, 512, 'gb', 'SATA');

        $single = $this->productWithProfile([$this->slot('M2-1', HardwareSubsystem::Storage, true, false, 'NVMe', $ssd256)]);
        $singleResult = $this->validateRecipe($single, null, 512, [
            $this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'M2-1', recovered: $ssd256, recovery: RecoveryValuationMethod::CentralApproved),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'M2-1', install: $ssd512),
        ]);
        $this->assertTrue($singleResult->valid, implode(' | ', $singleResult->errors));

        $double = $this->productWithProfile([
            $this->slot('M2-1', HardwareSubsystem::Storage, true, false, 'NVMe', $ssd256),
            $this->slot('M2-2', HardwareSubsystem::Storage, false, false, 'NVMe'),
        ]);
        $layout = [['slot_key' => 'M2-1', 'capacity_gb' => 256, 'interface' => 'NVME'], ['slot_key' => 'M2-2', 'capacity_gb' => 512, 'interface' => 'NVME']];
        $doubleResult = $this->validateRecipe($double, null, 768, [
            $this->line(1, UpgradeRecipeOperation::Keep, source: 'M2-1'),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'M2-2', install: $ssd512),
        ], $layout);
        $this->assertTrue($doubleResult->valid, implode(' | ', $doubleResult->errors));

        $mismatch = $this->validateRecipe($double, null, 768, [$this->line(1, UpgradeRecipeOperation::Install, target: 'M2-2', install: $sata512)]);
        $this->assertFalse($mismatch->valid);
        $this->assertStringContainsString('incompatible', implode(' ', $mismatch->errors));

        $unknown = $this->productWithProfile([$this->slot('M2-1', HardwareSubsystem::Storage, false, false, null)]);
        $result = $this->validateRecipe($unknown, null, 512, [$this->line(1, UpgradeRecipeOperation::Install, target: 'M2-1', install: $ssd512)]);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('interface facts are incomplete', implode(' ', $result->errors));

        $wrongLayout = [['slot_key' => 'M2-1', 'capacity_gb' => 768, 'interface' => 'NVME']];
        $result = $this->validateRecipe($double, null, 768, [
            $this->line(1, UpgradeRecipeOperation::Keep, source: 'M2-1'),
            $this->line(2, UpgradeRecipeOperation::Install, target: 'M2-2', install: $ssd512),
        ], $wrongLayout);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('target layout', implode(' ', $result->errors));
    }

    public function test_validation_rejects_inactive_component_max_ram_stale_version_and_target_mismatch(): void
    {
        $ram8 = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4');
        $ram16 = $this->fixtureComponent(ComponentType::Ram, 16, 'gb', 'DDR4');
        $product = $this->productWithProfile([
            $this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8),
            $this->slot('RAM-2', HardwareSubsystem::Ram, false, false, 'DDR4'),
        ], maxRam: 16384);

        $ram16->product->forceFill(['status' => ProductStatus::Inactive])->save();
        $result = $this->validateRecipe($product, 24576, null, [$this->line(1, UpgradeRecipeOperation::Install, target: 'RAM-2', install: $ram16)]);
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('inactive', implode(' ', $result->errors));
        $this->assertStringContainsString('exceeds', implode(' ', $result->errors));

        $recipe = $this->recipe($product, ['target_ram_mb' => 8192], [$this->line(1, UpgradeRecipeOperation::Keep, source: 'RAM-1')]);
        $recipe->salesConfiguration->forceFill(['hardware_profile_version' => 99])->save();
        $result = app(UpgradeRecipeValidationService::class)->validate($recipe->refresh());
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('stale', implode(' ', $result->errors));
    }

    public function test_profile_structural_change_versions_and_deactivates_existing_designs_without_inventory_mutation(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $ram8 = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4');
        $product = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8)]);
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, ['target_ram_mb' => 8192, 'suggested_selling_addon' => 999]));
        $recipe = UpgradeRecipe::query()->create($this->recipeData($configuration));
        $before = StockMovement::query()->count();

        app(HardwareProfileService::class)->save($product, [
            'ram_upgradeable' => true, 'max_supported_ram_mb' => 32768, 'storage_upgradeable' => true, 'notes' => null,
            'slots' => [$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8), $this->slot('RAM-2', HardwareSubsystem::Ram, false, false, 'DDR4')],
        ], $owner);

        $this->assertSame(2, $product->hardwareProfile->refresh()->profile_version);
        $this->assertFalse($configuration->refresh()->active);
        $this->assertFalse($recipe->refresh()->active);
        $this->assertSame($before, StockMovement::query()->count());
    }

    public function test_preferred_ordering_and_central_recovery_resolution_are_deterministic(): void
    {
        $component = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4', '30.0000');
        $product = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $component)]);
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, ['target_ram_mb' => 8192, 'suggested_selling_addon' => 999]));
        $alternate = UpgradeRecipe::query()->create($this->recipeData($configuration, ['name' => 'Alternate', 'priority' => 10]));
        $preferred = UpgradeRecipe::query()->create($this->recipeData($configuration, ['name' => 'Preferred', 'preferred' => true, 'priority' => 100]));
        $line = $preferred->lines()->create($this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'RAM-1', recovered: $component, recovery: RecoveryValuationMethod::CentralApproved));

        $this->assertSame([$preferred->id, $alternate->id], $configuration->recipes()->pluck('id')->all());
        $this->assertSame('30.0000', app(UpgradeRecipeService::class)->resolvedRecoveryValue($line));
        $this->assertSame('999.00', $configuration->suggested_selling_addon);
        $component->forceFill(['approved_oem_recovery_value' => '0.0000', 'recovery_approved_by_user_id' => null, 'recovery_approved_at' => null, 'recovery_reason' => null])->save();
        $this->assertSame('0.0000', app(UpgradeRecipeService::class)->resolvedRecoveryValue($line->refresh()));
    }

    public function test_permissions_registry_ui_and_sql_omission_preserve_financial_security(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager);
        $authorization = app(UpgradeAuthorization::class);
        $this->assertTrue($authorization->allows($owner, UpgradePermission::ApproveRecoveryOverride));
        $this->assertTrue($authorization->allows($admin, UpgradePermission::ManageRecipes));
        $this->assertFalse($authorization->allows($admin, UpgradePermission::ApproveRecoveryOverride));
        $this->assertFalse($authorization->allows($manager, UpgradePermission::ManageConfigurations));
        $this->assertSame([], app(AccessControlModuleRegistry::class)->reconcile()['missing']);

        $profileComponent = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4', '30.0000');
        $product = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $profileComponent)]);
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, ['target_ram_mb' => 8192, 'suggested_selling_addon' => 150, 'default_selling_price' => 1000]));
        $recipe = UpgradeRecipe::query()->create($this->recipeData($configuration, ['labour_unit_cost' => 25]));
        $this->actingAs($manager);
        $configRow = SalesConfigurationResource::getEloquentQuery()->findOrFail($configuration->id);
        $recipeRow = UpgradeRecipeResource::getEloquentQuery()->findOrFail($recipe->id);
        $this->assertArrayNotHasKey('suggested_selling_addon', $configRow->getAttributes());
        $this->assertArrayNotHasKey('default_selling_price', $configRow->getAttributes());
        $this->assertArrayNotHasKey('labour_unit_cost', $recipeRow->getAttributes());
        $this->assertArrayNotHasKey('approved_oem_recovery_value', $recipeRow->salesConfiguration->product->hardwareProfile->slots->first()?->baseComponent?->getAttributes() ?? []);

        $this->actingAs($owner)->get(SalesConfigurationResource::getUrl('index'))->assertOk()->assertSee('Sales Configurations');
        $this->actingAs($owner)->get(ProductHardwareProfileResource::getUrl('create'))->assertOk()->assertSee('Product Hardware Profile');
        $this->actingAs($owner)->get(UpgradeRecipeResource::getUrl('index', ['configuration' => $configuration->id]))->assertOk()->assertSee('Upgrade Recipes');
    }

    public function test_recovery_override_requires_explicit_permission_and_reason(): void
    {
        $admin = $this->user(EmployeeRole::Admin);
        $component = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4');
        $product = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $component)]);
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, ['target_ram_mb' => 8192]));

        $this->expectException(ValidationException::class);
        app(UpgradeRecipeService::class)->create($configuration, [
            'name' => 'Unauthorized override', 'preferred' => false, 'priority' => 100, 'active' => true,
            'lines' => [$this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'RAM-1', recovered: $component, recovery: RecoveryValuationMethod::Override) + ['recovery_value_override' => 25, 'override_reason' => 'Approved exception']],
        ], $admin);
    }

    public function test_authorized_recovery_override_records_value_reason_actor_and_timestamp_without_stock_movement(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $ram8 = $this->fixtureComponent(ComponentType::Ram, 8, 'gb', 'DDR4', '30.0000');
        $ram16 = $this->fixtureComponent(ComponentType::Ram, 16, 'gb', 'DDR4');
        $product = $this->productWithProfile([$this->slot('RAM-1', HardwareSubsystem::Ram, true, false, 'DDR4', $ram8)]);
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, ['target_ram_mb' => 16384, 'suggested_selling_addon' => 500]));
        $movementCount = StockMovement::query()->count();

        $recipe = app(UpgradeRecipeService::class)->create($configuration, [
            'name' => 'Approved lower recovery', 'preferred' => true, 'priority' => 1, 'labour_unit_cost' => 0, 'active' => true,
            'lines' => [
                $this->line(1, UpgradeRecipeOperation::RemoveAndReturn, source: 'RAM-1', recovered: $ram8, recovery: RecoveryValuationMethod::Override) + ['recovery_value_override' => 25, 'override_reason' => 'OEM low-speed module'],
                $this->line(2, UpgradeRecipeOperation::Install, target: 'RAM-1', install: $ram16),
            ],
        ], $owner);

        $line = $recipe->lines->first();
        $this->assertSame(RecoveryValuationMethod::Override, $line->recovery_valuation_method);
        $this->assertSame('25.0000', $line->recovery_value_override);
        $this->assertSame('OEM low-speed module', $line->override_reason);
        $this->assertSame($owner->id, $line->recovery_approved_by_user_id);
        $this->assertNotNull($line->recovery_approved_at);
        $this->assertSame('25.0000', app(UpgradeRecipeService::class)->resolvedRecoveryValue($line));
        $this->assertSame($movementCount, StockMovement::query()->count());
    }

    private function validateRecipe(Product $product, ?int $ramMb, ?int $storageGb, array $lines, ?array $layout = null): object
    {
        return app(UpgradeRecipeValidationService::class)->validate($this->recipe($product, ['target_ram_mb' => $ramMb, 'target_storage_total_gb' => $storageGb, 'target_storage_layout' => $layout], $lines));
    }

    private function recipe(Product $product, array $targets, array $lines): UpgradeRecipe
    {
        $configuration = SalesConfiguration::query()->create($this->configurationData($product, $targets));
        $recipe = UpgradeRecipe::query()->create($this->recipeData($configuration));
        foreach ($lines as $line) {
            $recipe->lines()->create($line);
        }

        return $recipe->refresh();
    }

    private function productWithProfile(array $slots, bool $ramUpgradeable = true, bool $storageUpgradeable = true, ?int $maxRam = 65536): Product
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $profile = ProductHardwareProfile::query()->create(['product_id' => $product->id, 'profile_version' => 1, 'ram_upgradeable' => $ramUpgradeable, 'max_supported_ram_mb' => $maxRam, 'storage_upgradeable' => $storageUpgradeable, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id]);
        foreach ($slots as $slot) {
            $profile->slots()->create($slot);
        }

        return $product->refresh();
    }

    private function fixtureComponent(ComponentType $type, float $capacity, string $unit, ?string $interface, string $recovery = '0.0000'): Component
    {
        $approval = bccomp($recovery, '0.0000', 4) > 0 ? User::factory()->create() : null;

        return Component::factory()->create([
            'component_type' => $type, 'specification' => "{$capacity}{$unit} {$interface}", 'capacity_value' => $capacity,
            'capacity_unit' => $unit, 'interface_type' => $interface, 'approved_oem_recovery_value' => $recovery,
            'recovery_approved_by_user_id' => $approval?->id, 'recovery_approved_at' => $approval ? now() : null,
            'recovery_reason' => $approval ? 'Approved test benchmark.' : null,
        ]);
    }

    private function slot(string $key, HardwareSubsystem $subsystem, bool $occupied, bool $soldered, ?string $interface, ?Component $component = null): array
    {
        return ['subsystem' => $subsystem->value, 'slot_key' => $key, 'interface_type' => $interface, 'is_soldered' => $soldered, 'is_occupied' => $occupied, 'base_component_id' => $component?->id, 'base_capacity_value' => $component?->capacity_value, 'base_capacity_unit' => $component?->capacity_unit, 'position' => 0];
    }

    private function line(int $sequence, UpgradeRecipeOperation $operation, ?string $source = null, ?string $target = null, ?Component $install = null, ?Component $recovered = null, RecoveryValuationMethod $recovery = RecoveryValuationMethod::NotApplicable): array
    {
        return ['sequence' => $sequence, 'operation' => $operation->value, 'source_slot_key' => $source, 'target_slot_key' => $target, 'install_component_id' => $install?->id, 'recovered_component_id' => $recovered?->id, 'quantity_per_laptop' => 1, 'recovery_valuation_method' => $recovery->value];
    }

    private function configurationData(Product $product, array $overrides = []): array
    {
        $user = User::factory()->create();

        return array_merge(['product_id' => $product->id, 'hardware_profile_version' => $product->hardwareProfile?->profile_version ?? 1, 'display_name' => 'Config '.Str::random(8), 'target_ram_mb' => null, 'target_storage_total_gb' => null, 'target_storage_layout' => null, 'suggested_selling_addon' => 0, 'default_selling_price' => null, 'active' => true, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id], array_filter($overrides, fn ($value): bool => $value !== null));
    }

    private function recipeData(SalesConfiguration $configuration, array $overrides = []): array
    {
        $user = User::factory()->create();

        return array_merge(['sales_configuration_id' => $configuration->id, 'hardware_profile_version' => $configuration->hardware_profile_version, 'name' => 'Recipe '.Str::random(8), 'preferred' => false, 'priority' => 100, 'labour_unit_cost' => 0, 'active' => true, 'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id], $overrides);
    }

    private function user(EmployeeRole $role): User
    {
        $email = $role === EmployeeRole::Owner ? 'upgrade-owner-'.Str::lower(Str::random(8)).'@example.com' : 'upgrade-'.Str::lower(Str::random(8)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role($role)->create(['email' => $email]);

        return $user->refresh();
    }
}
