<?php

namespace App\Services\DemoData\Scenarios;

use App\Actions\Catalog\CreateProductBrand;
use App\Actions\Catalog\CreateProductCategory;
use App\Actions\Products\CreateProduct;
use App\Actions\Responsibilities\CreateMarketplacePlatform;
use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Teams\CreateTeam;
use App\Actions\Users\CreateUserAndEmployee;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\DTOs\Employees\CreateEmployeeData;
use App\DTOs\Products\CreateProductData;
use App\DTOs\Responsibilities\CreateMarketplacePlatformData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\DTOs\Suppliers\CreateSupplierData;
use App\DTOs\Teams\CreateTeamData;
use App\DTOs\Users\CreateUserAccountData;
use App\DTOs\Users\CreateUserAndEmployeeData;
use App\Enums\ComponentType;
use App\Enums\EmployeeRole;
use App\Enums\HardwareSubsystem;
use App\Enums\MarketplaceReturnHandlingMode;
use App\Enums\ProductCondition;
use App\Enums\RecoveryValuationMethod;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\UpgradeRecipeOperation;
use App\Models\Component;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ResponsibilityAssignment;
use App\Models\SalesConfiguration;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\CatalogNameNormalizer;
use App\Services\Components\ComponentCatalogService;
use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoIdentity;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Upgrades\HardwareProfileService;
use App\Services\Upgrades\SalesConfigurationService;
use App\Services\Upgrades\UpgradeRecipeService;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ReferenceScenarioBuilder
{
    public function __construct(
        private readonly DemoIdentity $identity,
        private readonly CatalogNameNormalizer $names,
        private readonly CreateTeam $createTeam,
        private readonly CreateUserAndEmployee $createUser,
        private readonly CreateProductBrand $createBrand,
        private readonly CreateProductCategory $createCategory,
        private readonly CreateSupplier $createSupplier,
        private readonly CreateMarketplacePlatform $createPlatform,
        private readonly CreateProduct $createProduct,
        private readonly ComponentCatalogService $componentCatalog,
        private readonly HardwareProfileService $hardwareProfiles,
        private readonly SalesConfigurationService $configurations,
        private readonly UpgradeRecipeService $recipes,
        private readonly ResponsibilityAssignmentService $responsibilities,
    ) {}

    public function build(DemoContext $context): void
    {
        $this->teams($context);
        $this->employees($context);
        [$brands, $categories] = $this->catalog($context);
        $this->suppliers($context);
        $this->platforms($context);
        $this->products($context, $brands, $categories);
        $this->components($context, $brands, $categories);
        $this->upgradeScenarios($context);
        $this->responsibilities($context, $brands);

        $context->count('teams', $context->teams->count());
        $context->count('employees', $context->employees->count());
        $context->count('suppliers', $context->suppliers->count());
        $context->count('platforms', $context->platforms->count());
        $context->count('products', $context->products->count());
        $context->count('components', $context->components->count());
    }

    private function teams(DemoContext $context): void
    {
        foreach ([
            'sales' => 'Demo Sales Operations',
            'operations' => 'Demo Warehouse Operations',
            'service' => 'Demo Customer Service',
        ] as $key => $name) {
            $team = Team::query()->where('name', $name)->first();
            if ($team === null) {
                $team = $this->createTeam->handle(new CreateTeamData($name, $this->identity->note('Deterministic demo team.'), true), $context->owner);
            }
            $this->assert($team->status && $team->description === $this->identity->note('Deterministic demo team.'), "Demo Team [{$name}] has an unexpected fingerprint.");
            $context->teams->put($key, $team);
        }
    }

    private function employees(DemoContext $context): void
    {
        $definitions = [
            'admin' => ['Demo Admin', EmployeeRole::Admin, 'operations', 'ERP Administrator'],
            'manager1' => ['Demo Sales Manager', EmployeeRole::Manager, 'sales', 'Sales Manager'],
            'manager2' => ['Demo Service Manager', EmployeeRole::Manager, 'service', 'Service Manager'],
            'staff1' => ['Demo Sales Staff', EmployeeRole::Staff, 'sales', 'Sales Executive'],
            'staff2' => ['Demo Warehouse Staff', EmployeeRole::Staff, 'operations', 'Warehouse Assistant'],
            'staff3' => ['Demo Service Staff', EmployeeRole::Staff, 'service', 'Service Coordinator'],
        ];

        foreach ($definitions as $key => [$name, $role, $teamKey, $designation]) {
            $email = $this->identity->emails()[$key];
            $user = User::query()->where('email', $email)->with('employee')->first();
            if ($user === null) {
                $employee = $this->createUser->handle(new CreateUserAndEmployeeData(
                    new CreateUserAccountData($name, $email, (string) config('demo.password')),
                    new CreateEmployeeData(0, $name, $email, $designation, $role, true, null, $context->teams[$teamKey]->id, CarbonImmutable::now()->subMonths(8)),
                ), $context->owner);
            } else {
                $employee = $user->employee;
            }

            $this->assert(
                $employee instanceof Employee
                && $employee->name === $name
                && $employee->email === $email
                && $employee->role === $role
                && $employee->team_id === $context->teams[$teamKey]->id
                && $employee->status,
                "Demo Employee [{$email}] has an unexpected fingerprint.",
            );
            $context->employees->put($key, $employee);
        }
    }

    /** @return array{0: array<string, ProductBrand>, 1: array<string, ProductCategory>} */
    private function catalog(DemoContext $context): array
    {
        $brands = [];
        foreach (['HP', 'Dell', 'Lenovo', 'Acer', 'Kingston', 'Samsung'] as $name) {
            $normalized = $this->names->normalize($name);
            $brand = ProductBrand::query()->where('normalized_name', $normalized)->first();
            if ($brand === null) {
                $brand = $this->createBrand->handle(new CreateCatalogItemData($name), $context->owner);
            }
            $this->assert($brand->status, "Required Brand [{$name}] is inactive.");
            $brands[$name] = $brand;
        }

        $categories = [];
        foreach (['Laptops', 'Upgrade Components'] as $name) {
            $normalized = $this->names->normalize($name);
            $category = ProductCategory::query()->where('normalized_name', $normalized)->first();
            if ($category === null) {
                $category = $this->createCategory->handle(new CreateCatalogItemData($name), $context->owner);
            }
            $this->assert($category->status, "Required Category [{$name}] is inactive.");
            $categories[$name] = $category;
        }

        return [$brands, $categories];
    }

    private function suppliers(DemoContext $context): void
    {
        foreach (range(1, 5) as $index) {
            $name = sprintf('Demo Supplier %02d', $index);
            $supplier = Supplier::query()->where('name', $name)->first();
            if ($supplier === null) {
                $supplier = $this->createSupplier->handle(new CreateSupplierData(
                    name: $name,
                    contactPerson: 'Demo Contact',
                    phone: '+971000000000',
                    notes: $this->identity->note('Supplier fixture.'),
                ), $context->owner);
            }
            $this->assert($supplier->status && $supplier->notes === $this->identity->note('Supplier fixture.'), "Demo Supplier [{$name}] has an unexpected fingerprint.");
            $context->suppliers->push($supplier);
        }
    }

    private function platforms(DemoContext $context): void
    {
        $definitions = [
            ['Demo Amazon UAE', 'demo_amazon_uae', true],
            ['Demo Noon UAE', 'demo_noon_uae', true],
            ['Demo Website', 'demo_website', false],
            ['Demo Shop Counter', 'demo_shop_counter', false],
            ['Demo Marketplace', 'demo_marketplace', true],
        ];
        foreach ($definitions as [$name, $code, $claims]) {
            $platform = MarketplacePlatform::query()->where('code', $code)->first();
            if ($platform === null) {
                $platform = $this->createPlatform->handle(new CreateMarketplacePlatformData(
                    name: $name,
                    code: $code,
                    returnHandlingMode: MarketplaceReturnHandlingMode::ReturnDirectlyToCompany,
                    defaultReturnReceivingWarehouseId: $context->warehouse->id,
                    customerReturnClaimsEnabled: $claims,
                    claimProgramName: $claims ? 'Demo Safe-T' : null,
                ), $context->owner);
            }
            $this->assert(
                $platform->status
                && $platform->name === $name
                && $platform->default_return_receiving_warehouse_id === $context->warehouse->id
                && $platform->customer_return_claims_enabled === $claims,
                "Demo Marketplace Platform [{$code}] has an unexpected fingerprint.",
            );
            $context->platforms->push($platform);
        }
    }

    private function products(DemoContext $context, array $brands, array $categories): void
    {
        $brandNames = ['HP', 'Dell', 'Lenovo', 'Acer'];
        foreach (range(1, 32) as $index) {
            $brandName = $brandNames[($index - 1) % count($brandNames)];
            $model = sprintf('DEMO-LT-%03d', $index);
            $description = $this->identity->note('Base product '.$model.'.');
            $product = Product::query()->products()->where('model', $model)->where('description', 'like', $this->identity->marker().'%')->first();
            $selling = number_format(1400 + ($index * 35), 2, '.', '');
            if ($product === null) {
                $product = $this->createProduct->handle(new CreateProductData(
                    name: "{$brandName} DemoBook {$index} 14-inch Business Laptop",
                    brandId: $brands[$brandName]->id,
                    categoryId: $categories['Laptops']->id,
                    condition: $index % 5 === 0 ? ProductCondition::OpenBox : ProductCondition::New,
                    model: $model,
                    processor: $index % 2 === 0 ? 'Intel Core i7' : 'Intel Core i5',
                    ram: '8GB DDR4 3200',
                    storage: '256GB NVMe SSD',
                    screenSize: '14 inch',
                    graphics: 'Integrated',
                    color: $index % 2 === 0 ? 'Silver' : 'Black',
                    warranty: 12,
                    sellingPrice: $selling,
                    sellingPriceProvided: true,
                    description: $description,
                    duplicateOverrideReason: 'Approved deterministic staging demo catalogue.',
                ), $context->owner);
            }
            $product = $product->refresh();
            $this->assert($product->description === $description && $product->model === $model && $product->status->value === 'active', "Demo Product [{$model}] has an unexpected fingerprint.");
            $context->products->push($product);
        }
    }

    private function components(DemoContext $context, array $brands, array $categories): void
    {
        $definitions = [
            ['RAM 8GB DDR4 3200', ComponentType::Ram, 8, 'gb', 'DDR4', '30.0000', 'Kingston'],
            ['RAM 16GB DDR4 3200', ComponentType::Ram, 16, 'gb', 'DDR4', '55.0000', 'Kingston'],
            ['RAM 32GB DDR4 3200', ComponentType::Ram, 32, 'gb', 'DDR4', '90.0000', 'Kingston'],
            ['SSD 256GB NVMe', ComponentType::Ssd, 256, 'gb', 'NVMe', '35.0000', 'Samsung'],
            ['SSD 512GB NVMe', ComponentType::Ssd, 512, 'gb', 'NVMe', '65.0000', 'Samsung'],
            ['SSD 1TB NVMe', ComponentType::Ssd, 1, 'tb', 'NVMe', '110.0000', 'Samsung'],
            ['Laptop Battery 50Wh', ComponentType::Battery, 50, 'other', null, '0.0000', 'HP'],
            ['Wi-Fi 6 Card', ComponentType::WifiCard, null, null, 'M.2', '0.0000', 'Intel'],
        ];

        foreach ($definitions as $index => [$name, $type, $capacity, $unit, $interface, $recovery, $brandName]) {
            if (! isset($brands[$brandName])) {
                $normalized = $this->names->normalize($brandName);
                $brands[$brandName] = ProductBrand::query()->where('normalized_name', $normalized)->first()
                    ?? $this->createBrand->handle(new CreateCatalogItemData($brandName), $context->owner);
            }
            $specification = $this->identity->note($name);
            $component = Component::query()->where('specification', $specification)->first();
            if ($component === null) {
                $component = $this->componentCatalog->create([
                    'name' => $name,
                    'brand_id' => $brands[$brandName]->id,
                    'category_id' => $categories['Upgrade Components']->id,
                    'model' => sprintf('DEMO-CMP-%02d', $index + 1),
                    'description' => $this->identity->note('Upgrade component fixture.'),
                    'component_type' => $type,
                    'specification' => $specification,
                    'capacity_value' => $capacity,
                    'capacity_unit' => $unit,
                    'interface_type' => $interface,
                    'approved_oem_recovery_value' => $recovery,
                    'recovery_reason' => bccomp($recovery, '0.0000', 4) > 0 ? 'Approved staging demonstration benchmark.' : null,
                ], $context->owner);
            }
            $this->assert($component->component_type === $type && (string) $component->approved_oem_recovery_value === $recovery, "Demo Component [{$name}] has an unexpected fingerprint.");
            $context->components->push($component->load('product'));
        }
    }

    private function upgradeScenarios(DemoContext $context): void
    {
        $product = $context->products[0];
        $ram8 = $context->components[0];
        $ram16 = $context->components[1];
        $ram32 = $context->components[2];
        $ssd256 = $context->components[3];
        $ssd512 = $context->components[4];
        $ssd1tb = $context->components[5];

        if ($product->hardwareProfile === null) {
            $this->hardwareProfiles->save($product, $this->profileData($ram8, $ssd256), $context->owner);
        }
        foreach ([
            '16_512' => ['16GB / 512GB', 16384, 512, $ram16, $ssd512, 250, 1750],
            '32_1tb' => ['32GB / 1TB', 32768, 1024, $ram32, $ssd1tb, 520, 2050],
        ] as $key => [$name, $ram, $storage, $installRam, $installSsd, $addon, $selling]) {
            $display = $this->identity->marker().' '.$name;
            $configuration = SalesConfiguration::query()->where('product_id', $product->id)->where('display_name', $display)->first();
            if ($configuration === null) {
                $configuration = $this->configurations->create([
                    'product_id' => $product->id, 'display_name' => $display,
                    'target_ram_mb' => $ram, 'target_storage_total_gb' => $storage,
                    'target_storage_layout' => [['slot_key' => 'SSD-1', 'capacity_gb' => $storage, 'interface' => 'NVMe']],
                    'suggested_selling_addon' => $addon, 'default_selling_price' => $selling, 'active' => true,
                ], $context->owner);
            }
            $recipeName = $this->identity->marker().' '.$name.' preferred recipe';
            $recipe = UpgradeRecipe::query()->where('sales_configuration_id', $configuration->id)->where('name', $recipeName)->first();
            if ($recipe === null) {
                $recipe = $this->recipes->create($configuration, [
                    'name' => $recipeName, 'preferred' => true, 'priority' => 1,
                    'labour_unit_cost' => 20, 'active' => true,
                    'lines' => [
                        $this->recipeLine(1, UpgradeRecipeOperation::RemoveAndReturn, 'RAM-1', null, null, $ram8, RecoveryValuationMethod::CentralApproved),
                        $this->recipeLine(2, UpgradeRecipeOperation::Install, null, 'RAM-1', $installRam),
                        $this->recipeLine(3, UpgradeRecipeOperation::RemoveAndReturn, 'SSD-1', null, null, $ssd256, RecoveryValuationMethod::CentralApproved),
                        $this->recipeLine(4, UpgradeRecipeOperation::Install, null, 'SSD-1', $installSsd),
                    ],
                ], $context->owner);
            }
            $context->configurations->put($key, $configuration);
            $context->recipes->put($key, $recipe);
        }

        $staleProduct = $context->products[1];
        $staleDisplay = $this->identity->marker().' Stale 16GB configuration';
        $stale = SalesConfiguration::query()->where('product_id', $staleProduct->id)->where('display_name', $staleDisplay)->first();
        if ($stale === null) {
            $this->hardwareProfiles->save($staleProduct, $this->profileData($ram8, $ssd256), $context->owner);
            $stale = $this->configurations->create([
                'product_id' => $staleProduct->id, 'display_name' => $staleDisplay,
                'target_ram_mb' => 16384, 'target_storage_total_gb' => null,
                'suggested_selling_addon' => 175, 'default_selling_price' => 1700, 'active' => true,
            ], $context->owner);
            $profile = $this->profileData($ram8, $ssd256);
            $profile['slots'][] = [
                'subsystem' => HardwareSubsystem::Ram->value, 'slot_key' => 'RAM-2', 'interface_type' => 'DDR4',
                'is_soldered' => false, 'is_occupied' => false, 'base_component_id' => null,
                'base_capacity_value' => null, 'base_capacity_unit' => null, 'position' => 2,
            ];
            $this->hardwareProfiles->save($staleProduct->refresh(), $profile, $context->owner);
        }
        $context->configurations->put('stale', $stale->refresh());
    }

    private function responsibilities(DemoContext $context, array $brands): void
    {
        foreach (['staff1' => 'HP', 'staff2' => 'Dell', 'staff3' => 'Lenovo'] as $employeeKey => $brandName) {
            $key = $this->identity->uuid('responsibility/'.$employeeKey);
            $existing = ResponsibilityAssignment::query()->where('idempotency_key', $key)->first();
            if ($existing === null) {
                $this->responsibilities->create(new CreateResponsibilityAssignmentData(
                    employeeId: $context->employees[$employeeKey]->id,
                    mode: ResponsibilityAssignmentMode::Scope,
                    brandId: $brands[$brandName]->id,
                    platformId: null,
                    productId: null,
                    productInventoryId: null,
                    assignedQuantity: null,
                    effectiveAt: CarbonImmutable::now()->subMonths(6)->toDateTimeString(),
                    reason: 'Approved staging demonstration responsibility.',
                    notes: $this->identity->note('Responsibility scope fixture.'),
                    idempotencyKey: $key,
                ), $context->owner);
            }
        }
    }

    /** @return array<string, mixed> */
    private function profileData(Component $ram, Component $ssd): array
    {
        return [
            'ram_upgradeable' => true, 'max_supported_ram_mb' => 65536,
            'storage_upgradeable' => true, 'notes' => $this->identity->note('Hardware profile fixture.'),
            'slots' => [
                ['subsystem' => HardwareSubsystem::Ram->value, 'slot_key' => 'RAM-1', 'interface_type' => 'DDR4', 'is_soldered' => false, 'is_occupied' => true, 'base_component_id' => $ram->id, 'base_capacity_value' => 8, 'base_capacity_unit' => 'gb', 'position' => 0],
                ['subsystem' => HardwareSubsystem::Storage->value, 'slot_key' => 'SSD-1', 'interface_type' => 'NVMe', 'is_soldered' => false, 'is_occupied' => true, 'base_component_id' => $ssd->id, 'base_capacity_value' => 256, 'base_capacity_unit' => 'gb', 'position' => 1],
            ],
        ];
    }

    private function recipeLine(int $sequence, UpgradeRecipeOperation $operation, ?string $source, ?string $target, ?Component $install = null, ?Component $recovered = null, RecoveryValuationMethod $recovery = RecoveryValuationMethod::NotApplicable): array
    {
        return [
            'sequence' => $sequence, 'operation' => $operation,
            'source_slot_key' => $source, 'target_slot_key' => $target,
            'install_component_id' => $install?->id, 'recovered_component_id' => $recovered?->id,
            'quantity_per_laptop' => 1, 'recovery_valuation_method' => $recovery,
        ];
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
