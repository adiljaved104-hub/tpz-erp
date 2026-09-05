<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Exceptions\DuplicateSupplierInvoiceException;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_and_self_approves_a_purchase_with_safe_totals_and_activity(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            $supplier->id,
            $warehouse->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, 3, '100.0000', '10.00', '5.00')],
            ' inv / 001 ',
            now()->toDateString(),
        ), $owner);

        $this->assertSame('INV-001', $purchase->supplier_invoice_number_normalized);
        $this->assertSame('304.50', $purchase->grand_total);
        $this->assertSame('96.6667', $purchase->items->first()->inventory_unit_cost);

        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Sole Owner approval.', true), $owner);

        $this->assertSame(PurchaseStatus::Approved, $purchase->status);
        $this->assertTrue($purchase->self_approved);
        $this->assertDatabaseHas('activity_logs', ['event' => 'purchase.approved', 'subject_id' => $purchase->id]);
        $this->assertStringNotContainsString('96.6667', ActivityLog::query()->where('subject_id', $purchase->id)->pluck('properties')->implode(' '));
    }

    public function test_same_normalized_invoice_is_blocked_for_one_supplier_but_allowed_for_another(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $supplier = Supplier::factory()->create();
        $otherSupplier = Supplier::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        $make = fn (Supplier $for, string $invoice): CreatePurchaseData => new CreatePurchaseData(
            $for->id, $warehouse->id, now()->toDateString(), [new PurchaseItemData($product->id, 1, '10.0000')], $invoice, now()->toDateString()
        );

        $first = app(CreatePurchase::class)->handle($make($supplier, 'INV_001'), $owner);

        try {
            app(CreatePurchase::class)->handle($make($supplier, ' inv / 001 '), $owner);
            $this->fail('Duplicate invoice was not rejected.');
        } catch (DuplicateSupplierInvoiceException $exception) {
            $this->assertStringContainsString($first->reference, $exception->getMessage());
        }

        $this->assertInstanceOf(Purchase::class, app(CreatePurchase::class)->handle($make($otherSupplier, 'INV-001'), $owner));
    }

    public function test_database_unique_constraint_is_the_final_duplicate_invoice_guard(): void
    {
        $supplier = Supplier::factory()->create();
        Purchase::factory()->create([
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'INV_001',
            'supplier_invoice_number_normalized' => 'INV-001',
        ]);

        $this->expectException(QueryException::class);
        Purchase::factory()->create([
            'supplier_id' => $supplier->id,
            'supplier_invoice_number' => 'inv / 001',
            'supplier_invoice_number_normalized' => 'INV-001',
        ]);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create();

        return $user->refresh();
    }
}
