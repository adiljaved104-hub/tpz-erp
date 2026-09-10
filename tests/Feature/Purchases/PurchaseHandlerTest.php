<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\UpdateDraftPurchase;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\UpdatePurchaseData;
use App\Enums\EmployeeRole;
use App\Filament\Resources\Purchases\Pages\CreatePurchase as CreatePurchasePage;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentCategory;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PurchaseHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_product_brand_responsibility_defaults_the_purchase_handler(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $handler = $this->user(EmployeeRole::Staff)->employee;
        $warehouse = Warehouse::factory()->create();
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        $this->brandResponsibility($handler, $brand);

        $purchase = app(CreatePurchase::class)->handle($this->createData($warehouse, [$product]), $owner);

        $this->assertSame($handler->id, $purchase->handled_by_employee_id);
    }

    public function test_mixed_responsibilities_do_not_silently_choose_a_handler(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $warehouse = Warehouse::factory()->create();
        $first = $this->productFor($this->user(EmployeeRole::Staff)->employee);
        $second = $this->productFor($this->user(EmployeeRole::Staff)->employee);

        $purchase = app(CreatePurchase::class)->handle($this->createData($warehouse, [$first, $second]), $owner);

        $this->assertNull($purchase->handled_by_employee_id);
    }

    public function test_owner_can_resolve_ambiguity_with_an_active_employee(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $selected = $this->user(EmployeeRole::Staff)->employee;
        $warehouse = Warehouse::factory()->create();
        $first = $this->productFor($selected);
        $second = $this->productFor($this->user(EmployeeRole::Staff)->employee);

        $purchase = app(CreatePurchase::class)->handle(
            $this->createData($warehouse, [$first, $second], $selected->id),
            $owner,
        );

        $this->assertSame($selected->id, $purchase->handled_by_employee_id);
    }

    public function test_manager_cannot_choose_a_handler_or_purchase_outside_responsibility_scope(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $selected = $this->user(EmployeeRole::Staff)->employee;
        $warehouse = Warehouse::factory()->create();
        $product = $this->productFor($manager->employee);

        try {
            app(CreatePurchase::class)->handle($this->createData($warehouse, [$product], $selected->id), $manager);
            $this->fail('Manager must not explicitly choose a Purchase handler.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handled_by_employee_id', $exception->errors());
        }

        $outsideScope = Product::factory()->create();

        try {
            app(CreatePurchase::class)->handle($this->createData($warehouse, [$outsideScope]), $manager);
            $this->fail('Manager must not purchase a Product outside their Responsibility scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items', $exception->errors());
        }

        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_existing_handler_is_preserved_when_a_draft_purchase_is_edited(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $historicalHandler = $this->user(EmployeeRole::Staff)->employee;
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $purchase = Purchase::factory()->create([
            'warehouse_id' => $warehouse->id,
            'created_by_user_id' => $owner->id,
            'handled_by_employee_id' => $historicalHandler->id,
        ]);

        $updated = app(UpdateDraftPurchase::class)->handle(
            $purchase,
            $this->updateData($warehouse, [$product]),
            $owner,
        );

        $this->assertSame($historicalHandler->id, $updated->handled_by_employee_id);
    }

    public function test_product_and_category_responsibilities_match_but_quantity_responsibility_does_not_own_purchase(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $directHandler = $this->user(EmployeeRole::Staff)->employee;
        $categoryHandler = $this->user(EmployeeRole::Staff)->employee;
        $quantityOnly = $this->user(EmployeeRole::Staff)->employee;
        $warehouse = Warehouse::factory()->create();
        $category = ProductCategory::factory()->create();
        $directProduct = Product::factory()->create();
        $categoryProduct = Product::factory()->create(['category_id' => $category->id, 'category' => $category->name]);
        $unowned = Product::factory()->create();

        $direct = ResponsibilityAssignment::factory()->create(['employee_id' => $directHandler->id]);
        ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $direct->id, 'product_id' => $directProduct->id]);
        $categoryAssignment = ResponsibilityAssignment::factory()->create(['employee_id' => $categoryHandler->id]);
        ResponsibilityAssignmentCategory::query()->create(['assignment_id' => $categoryAssignment->id, 'product_category_id' => $category->id]);

        $quantityAssignment = ResponsibilityAssignment::factory()->create(['employee_id' => $quantityOnly->id]);
        $inventory = $unowned->inventories()->create([
            'warehouse_id' => $warehouse->id,
            'available_quantity' => 10,
            'reserved_quantity' => 0,
            'damaged_quantity' => 0,
            'average_cost' => '0.0000',
        ]);
        $quantityAssignment->quantityScope()->create(['product_inventory_id' => $inventory->id, 'assigned_quantity' => 5]);

        $this->assertSame($directHandler->id, app(CreatePurchase::class)->handle($this->createData($warehouse, [$directProduct]), $owner)->handled_by_employee_id);
        $this->assertSame($categoryHandler->id, app(CreatePurchase::class)->handle($this->createData($warehouse, [$categoryProduct]), $owner)->handled_by_employee_id);
        $this->assertNull(app(CreatePurchase::class)->handle($this->createData($warehouse, [$unowned]), $owner)->handled_by_employee_id);
    }

    public function test_purchase_ui_shows_handler_on_create_list_and_view(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $handler = $this->user(EmployeeRole::Staff)->employee;
        $purchase = Purchase::factory()->create(['handled_by_employee_id' => $handler->id]);
        $this->actingAs($owner);

        Livewire::test(CreatePurchasePage::class)->assertSee('Handled By');
        Livewire::test(ListPurchases::class)->assertSee('Handled By')->assertSee($handler->name);
        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])->assertSee('Handled By')->assertSee($handler->name);
    }

    public function test_manager_product_search_remains_limited_to_responsibility_scope(): void
    {
        $manager = $this->user(EmployeeRole::Manager);
        $warehouse = Warehouse::factory()->create();
        $allowed = $this->productFor($manager->employee);
        $outsideScope = Product::factory()->create();
        $this->actingAs($manager);

        $component = Livewire::test(CreatePurchasePage::class)->fillForm(['warehouse_id' => $warehouse->id]);
        $productField = collect($component->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field->getName() === 'product_id');

        $this->assertInstanceOf(Select::class, $productField);
        $this->assertArrayHasKey($allowed->id, $productField->getSearchResults($allowed->sku));
        $this->assertArrayNotHasKey($outsideScope->id, $productField->getSearchResults($outsideScope->sku));

        Livewire::test(CreatePurchasePage::class)
            ->fillForm(['warehouse_id' => $warehouse->id])
            ->mountFormComponentAction('purchase-items', 'bulkAddProducts')
            ->assertFormFieldExists('product_ids', function (Select $field) use ($allowed, $outsideScope): bool {
                return array_key_exists($allowed->id, $field->getSearchResults($allowed->sku))
                    && ! array_key_exists($outsideScope->id, $field->getSearchResults($outsideScope->sku));
            });
    }

    private function productFor(Employee $employee): Product
    {
        $brand = ProductBrand::factory()->create();
        $product = Product::factory()->create(['brand_id' => $brand->id, 'brand' => $brand->name]);
        $this->brandResponsibility($employee, $brand);

        return $product;
    }

    private function brandResponsibility(Employee $employee, ProductBrand $brand): ResponsibilityAssignment
    {
        $assignment = ResponsibilityAssignment::factory()->create(['employee_id' => $employee->id]);
        ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $brand->id]);

        return $assignment;
    }

    /** @param array<int, Product> $products */
    private function createData(Warehouse $warehouse, array $products, ?int $handlerId = null): CreatePurchaseData
    {
        return new CreatePurchaseData(
            supplierId: null,
            warehouseId: $warehouse->id,
            purchaseDate: now()->toDateString(),
            items: array_map(fn (Product $product): PurchaseItemData => new PurchaseItemData($product->id, 1, '100.0000'), $products),
            handledByEmployeeId: $handlerId,
        );
    }

    /** @param array<int, Product> $products */
    private function updateData(Warehouse $warehouse, array $products): UpdatePurchaseData
    {
        return new UpdatePurchaseData(
            supplierId: null,
            warehouseId: $warehouse->id,
            purchaseDate: now()->toDateString(),
            items: array_map(fn (Product $product): PurchaseItemData => new PurchaseItemData($product->id, 1, '100.0000'), $products),
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email]);

        return $user->refresh();
    }
}
