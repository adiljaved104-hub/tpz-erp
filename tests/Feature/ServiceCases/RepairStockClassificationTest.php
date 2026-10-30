<?php

namespace Tests\Feature\ServiceCases;

use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\EmployeeRole;
use App\Enums\ProductCondition;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\InternalRepairs\Pages\ViewInternalRepair;
use App\Models\DamagedStockEvent;
use App\Models\Employee;
use App\Models\InventoryAllocationBalance;
use App\Models\InventoryAllocationTransitionLine;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarrantyRepair;
use App\Services\Inventory\DamagedStockAvailabilityService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\ServiceCases\RepairStockClassificationService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepairStockClassificationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('saleConditions')]
    public function test_qc_pass_waits_and_conversion_posts_exactly_once_with_weighted_cost(ProductCondition $condition): void
    {
        [$owner, $sourceProduct, $source, $damage, $repair] = $this->foundation();
        $destinationProduct = $this->destination($sourceProduct, $condition);
        $destination = ProductInventory::factory()->create([
            'product_id' => $destinationProduct->id, 'warehouse_id' => $source->warehouse_id,
            'available_quantity' => 2, 'reserved_quantity' => 1, 'damaged_quantity' => 0,
            'average_cost' => '1000.0000',
        ]);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        $key = (string) Str::uuid();
        $beforeOwned = $source->totalOnHand() + $destination->totalOnHand();

        $this->assertSame(WarrantyRepairStatus::PendingClassification, $repair->status);
        $this->assertSame(1, $source->refresh()->damaged_quantity);
        $this->assertSame(0, $source->available_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(0, app(DamagedStockAvailabilityService::class)->availableForRepair($damage));
        Livewire::actingAs($owner)->test(ViewInternalRepair::class, ['record' => $repair->getRouteKey()])
            ->assertActionVisible('classifyStock');

        $service = app(RepairStockClassificationService::class);
        $classification = $service->approve($repair, $this->decision($condition, $destinationProduct->id, $account->id, $key), $owner);
        $duplicate = $service->approve($repair->refresh(), $this->decision($condition, $destinationProduct->id, $account->id, $key), $owner);

        $this->assertSame($classification->id, $duplicate->id);
        $this->assertSame(WarrantyRepairStatus::Completed, $repair->refresh()->status);
        Livewire::actingAs($owner)->test(ViewInternalRepair::class, ['record' => $repair->getRouteKey()])
            ->assertActionHidden('classifyStock');
        $this->assertSame($classification->source_movement_id, $repair->repair_qc_movement_id);
        $this->assertSame(0, $source->refresh()->damaged_quantity);
        $this->assertSame(0, $source->available_quantity);
        $this->assertSame(3, $destination->refresh()->available_quantity);
        $this->assertSame(1, $destination->reserved_quantity);
        $this->assertSame('1166.6667', $destination->average_cost);
        $this->assertSame($beforeOwned, $source->totalOnHand() + $destination->totalOnHand());
        $this->assertSame(0, app(DamagedStockAvailabilityService::class)->remaining($damage));
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('stock_movements', ['id' => $classification->source_movement_id, 'movement_type' => 'repair_classification_out', 'damaged_delta' => -1]);
        $this->assertDatabaseHas('stock_movements', ['id' => $classification->destination_movement_id, 'movement_type' => 'repair_classification_in', 'available_delta' => 1]);
        $this->assertSame(1, InventoryAllocationBalance::query()->where('account_id', $account->id)->where('product_inventory_id', $destination->id)->value('allocated_quantity'));
        $this->assertDatabaseCount('repair_stock_classifications', 1);
    }

    /** @return iterable<string, array{ProductCondition}> */
    public static function saleConditions(): iterable
    {
        yield 'open box' => [ProductCondition::OpenBox];
        yield 'renewed' => [ProductCondition::Renewed];
        yield 'refurbished' => [ProductCondition::Refurbished];
        yield 'used' => [ProductCondition::Used];
    }

    public function test_new_requires_verified_sealed_replacement_and_evidence_reference(): void
    {
        [$owner, $product, $source, , $repair] = $this->foundation();
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        $service = app(RepairStockClassificationService::class);
        $data = $this->decision(ProductCondition::New, $product->id, $account->id);

        try {
            $service->approve($repair, $data, $owner);
            $this->fail('Ordinary repair was classified New.');
        } catch (WarrantyRepairException) {
        }
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(1, $source->refresh()->damaged_quantity);

        $data['sealed_replacement_confirmed'] = true;
        $data['replacement_evidence_reference'] = 'SUPPLIER-SEALED-REPLACEMENT-123';
        $classification = $service->approve($repair, $data, $owner);
        $this->assertSame($source->id, $classification->destination_product_inventory_id);
        $this->assertSame(1, $source->refresh()->available_quantity);
        $this->assertSame(0, $source->damaged_quantity);
        $this->assertSame('1500.0000', $source->average_cost);
    }

    public function test_mismatched_or_missing_destination_never_posts_stock(): void
    {
        [$owner, $product, $source, , $repair] = $this->foundation();
        $wrong = $this->destination($product, ProductCondition::Renewed, ['ram' => '32 GB']);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);

        try {
            app(RepairStockClassificationService::class)->approve($repair,
                $this->decision(ProductCondition::Renewed, $wrong->id, $account->id), $owner);
            $this->fail('A different hardware SKU was accepted.');
        } catch (WarrantyRepairException) {
        }
        $this->assertSame(1, $source->refresh()->damaged_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('repair_stock_classifications', 0);
    }

    public function test_legacy_damage_requires_explicit_allocation_holder(): void
    {
        [$owner, $product, $source] = $this->foundation();
        $repair = WarrantyRepair::query()->create([
            'reference' => 'WR-2026-LEGACY-001', 'product_id' => $product->id, 'warehouse_id' => $source->warehouse_id,
            'product_inventory_id' => $source->id, 'quantity' => 1, 'source' => 'damaged_item',
            'issue_description' => 'Legacy repair', 'received_at' => now(), 'qc_at' => now(),
            'status' => WarrantyRepairStatus::PendingClassification, 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        $destination = $this->destination($product, ProductCondition::Renewed);

        $this->expectException(ValidationException::class);
        app(RepairStockClassificationService::class)->approve($repair,
            $this->decision(ProductCondition::Renewed, $destination->id, null), $owner);
    }

    public function test_original_damage_holder_is_preserved_when_converting_condition_sku(): void
    {
        [$owner, $product, $source, $damage, $repair] = $this->foundation();
        $destination = $this->destination($product, ProductCondition::Renewed);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        $line = InventoryAllocationTransitionLine::query()->create([
            'context_type' => 'damaged', 'context_id' => $damage->id, 'account_id' => $account->id,
            'source_product_inventory_id' => $source->id, 'quantity' => 1, 'remaining_quantity' => 1, 'status' => 'held',
        ]);

        $classification = app(RepairStockClassificationService::class)->approve($repair,
            $this->decision(ProductCondition::Renewed, $destination->id, $account->id), $owner);

        $this->assertSame('management_selected', $classification->allocation_resolution);
        $this->assertSame(0, $line->refresh()->remaining_quantity);
        $this->assertSame('classified', $line->status);
        $destinationInventory = ProductInventory::query()->where('product_id', $destination->id)->where('warehouse_id', $source->warehouse_id)->firstOrFail();
        $this->assertSame(1, InventoryAllocationBalance::query()->where('account_id', $account->id)->where('product_inventory_id', $destinationInventory->id)->value('allocated_quantity'));
    }

    public function test_staff_cannot_classify_even_when_a_repair_is_visible(): void
    {
        [, $product, , , $repair] = $this->foundation();
        $staff = User::factory()->create();
        Employee::factory()->for($staff)->role(EmployeeRole::Staff)->create(['email' => $staff->email]);
        $destination = $this->destination($product, ProductCondition::Renewed);

        $this->expectException(AuthorizationException::class);
        app(RepairStockClassificationService::class)->approve($repair,
            $this->decision(ProductCondition::Renewed, $destination->id, null), $staff);
    }

    public function test_conflicting_second_approval_cannot_duplicate_movements(): void
    {
        [$owner, $product, , , $repair] = $this->foundation();
        $destination = $this->destination($product, ProductCondition::Renewed);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);
        $service = app(RepairStockClassificationService::class);
        $service->approve($repair, $this->decision(ProductCondition::Renewed, $destination->id, $account->id), $owner);

        try {
            $service->approve($repair->refresh(), $this->decision(ProductCondition::Renewed, $destination->id, $account->id), $owner);
            $this->fail('A second classification was accepted.');
        } catch (WarrantyRepairException) {
        }
        $this->assertDatabaseCount('repair_stock_classifications', 1);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_customer_owned_service_cannot_be_classified_as_company_stock(): void
    {
        [$owner, $product, $inventory] = $this->foundation();
        $customerCase = WarrantyRepair::query()->create([
            'reference' => 'WR-2026-CUSTOMER-001', 'product_id' => $product->id,
            'warehouse_id' => $inventory->warehouse_id, 'product_inventory_id' => $inventory->id,
            'quantity' => 1, 'source' => 'manual', 'issue_description' => 'Customer-owned property',
            'received_at' => now(), 'status' => WarrantyRepairStatus::PendingClassification,
            'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);

        $this->expectException(AuthorizationException::class);
        app(RepairStockClassificationService::class)->approve($customerCase,
            $this->decision(ProductCondition::New, $product->id, null), $owner);
    }

    public function test_unknown_cost_blocks_conversion_without_inventory_or_allocation_changes(): void
    {
        [$owner, $product, $source, , $repair] = $this->foundation();
        $source->forceFill(['average_cost' => null])->save();
        $destination = $this->destination($product, ProductCondition::Renewed);
        $account = app(InventoryAllocationService::class)->employeeAccount($owner->employee->id);

        try {
            app(RepairStockClassificationService::class)->approve($repair,
                $this->decision(ProductCondition::Renewed, $destination->id, $account->id), $owner);
            $this->fail('An unvalued damaged unit was converted.');
        } catch (WarrantyRepairException) {
        }
        $this->assertSame(1, $source->refresh()->damaged_quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('repair_stock_classifications', 0);
    }

    /** @return array{User, Product, ProductInventory, DamagedStockEvent, WarrantyRepair} */
    private function foundation(): array
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['email' => $owner->email]);
        $product = Product::factory()->create([
            'name' => 'HP EliteBook 840 G10', 'model' => '840 G10', 'processor_model' => 'i5-1335U',
            'ram' => '16 GB', 'storage' => '512 GB', 'condition' => ProductCondition::New,
        ]);
        $warehouse = Warehouse::factory()->create(['status' => true]);
        $inventory = ProductInventory::factory()->create([
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
            'available_quantity' => 0, 'reserved_quantity' => 0, 'damaged_quantity' => 1, 'average_cost' => '1500.0000',
        ]);
        $damage = DamagedStockEvent::query()->create([
            'reference' => 'DMG-2026-'.Str::upper(Str::random(8)), 'product_inventory_id' => $inventory->id,
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 1,
            'source' => DamagedStockSource::WarehouseDamage, 'reason' => 'Test damaged laptop',
            'occurred_at' => now(), 'reported_by_user_id' => $owner->id,
            'status' => DamagedStockStatus::Damaged, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $repair = app(WarrantyRepairService::class)->createFromDamagedItem($damage,
            ['idempotency_key' => (string) Str::uuid()], $owner);
        foreach ([WarrantyRepairStatus::SendToTechnician, WarrantyRepairStatus::InRepair, WarrantyRepairStatus::RepairCompleted,
            WarrantyRepairStatus::ReceivedBack, WarrantyRepairStatus::QcPending, WarrantyRepairStatus::ReadyToReturn] as $status) {
            $repair = app(WarrantyRepairService::class)->transition($repair, $status, $owner);
        }

        return [$owner, $product, $inventory, $damage, $repair];
    }

    /** @param array<string, mixed> $overrides */
    private function destination(Product $source, ProductCondition $condition, array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'HP EliteBook 840 G10', 'brand' => $source->brand, 'brand_id' => $source->brand_id,
            'category' => $source->category, 'category_id' => $source->category_id,
            'model' => $source->model, 'processor_model' => $source->processor_model,
            'ram' => $source->ram, 'storage' => $source->storage, 'condition' => $condition,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function decision(ProductCondition $condition, int $destinationId, ?int $accountId, ?string $key = null): array
    {
        return [
            'condition' => $condition->value, 'destination_product_id' => $destinationId,
            'allocation_account_id' => $accountId, 'reason' => 'QC confirmed the final stock condition.',
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }
}
