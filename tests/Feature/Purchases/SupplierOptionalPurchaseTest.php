<?php

namespace Tests\Feature\Purchases;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\ClosePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\UpdateDraftPurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\ClosePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseExportData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\DTOs\Purchases\UpdatePurchaseData;
use App\Enums\EmployeeRole;
use App\Enums\PurchaseStatus;
use App\Filament\Pages\Purchasing\ExpectedDeliveriesReport;
use App\Filament\Pages\Purchasing\OpenPurchasesReport;
use App\Filament\Pages\Purchasing\PartiallyReceivedPurchasesReport;
use App\Filament\Pages\Purchasing\ProductPurchaseCostHistoryReport;
use App\Filament\Pages\Purchasing\ReceivingHistoryReport;
use App\Filament\Pages\Purchasing\SupplierInvoiceRegister;
use App\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Filament\Resources\Purchases\Pages\CreatePurchase as CreatePurchasePage;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\ReceivePurchase as ReceivePurchasePage;
use App\Filament\Resources\Purchases\Pages\ViewPurchase;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchases\PurchaseExportService;
use App\Services\Purchases\PurchaseReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierOptionalPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplierless_purchase_can_complete_the_full_multi_grn_lifecycle(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            supplierId: null,
            warehouseId: $warehouse->id,
            purchaseDate: now()->toDateString(),
            items: [new PurchaseItemData($product->id, 5, '20.0000')],
            supplierInvoiceNumber: ' informational / 001 ',
            externalAccountingReference: '  QB-BILL-101  ',
        ), $owner);

        $this->assertNull($purchase->supplier_id);
        $this->assertSame('informational / 001', $purchase->supplier_invoice_number);
        $this->assertSame('INFORMATIONAL-001', $purchase->supplier_invoice_number_normalized);
        $this->assertSame('QB-BILL-101', $purchase->external_accounting_reference);

        $purchase = app(ApprovePurchase::class)->handle(
            $purchase,
            new ApprovePurchaseData('Supplierless stock purchase approved.', true),
            $owner,
        );
        $line = $purchase->items()->sole();

        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 2, 0, 0)],
            now()->toDateTimeString(),
            (string) Str::uuid(),
        ), $owner);
        $this->assertSame(PurchaseStatus::PartiallyReceived, $purchase->refresh()->status);

        app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 3, 0, 0)],
            now()->toDateTimeString(),
            (string) Str::uuid(),
        ), $owner);

        $this->assertSame(PurchaseStatus::FullyReceived, $purchase->refresh()->status);
        $this->assertSame(2, PurchaseReceipt::query()->count());
        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(5, ProductInventory::query()->sole()->available_quantity);
        $this->assertSame('20.0000', ProductInventory::query()->sole()->average_cost);

        $closed = app(ClosePurchase::class)->handle(
            $purchase,
            new ClosePurchaseData('Receiving complete.', true),
            $owner,
        );
        $this->assertSame(PurchaseStatus::Closed, $closed->status);
    }

    public function test_draft_can_change_from_supplier_to_no_supplier_and_blank_external_reference_becomes_null(): void
    {
        $owner = $this->owner();
        $supplier = Supplier::factory()->create();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $line = new PurchaseItemData($product->id, 1, '10.0000');
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            $supplier->id, $warehouse->id, now()->toDateString(), [$line], externalAccountingReference: 'REF-1',
        ), $owner);

        $purchase = app(UpdateDraftPurchase::class)->handle($purchase, new UpdatePurchaseData(
            null, $warehouse->id, now()->toDateString(), [$line], externalAccountingReference: '   ',
        ), $owner);

        $this->assertNull($purchase->supplier_id);
        $this->assertNull($purchase->external_accounting_reference);
    }

    public function test_supplierless_invoices_are_informational_while_supplier_duplicates_remain_enforced(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $line = [new PurchaseItemData($product->id, 1, '10.0000')];

        foreach (['INV / 001', 'inv_001'] as $invoice) {
            app(CreatePurchase::class)->handle(new CreatePurchaseData(
                null, $warehouse->id, now()->toDateString(), $line, $invoice,
            ), $owner);
        }

        $this->assertSame(2, Purchase::query()->whereNull('supplier_id')->where('supplier_invoice_number_normalized', 'INV-001')->count());
    }

    public function test_supplierless_purchase_is_visible_in_ui_reports_cost_history_and_export(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();
        $purchase = app(CreatePurchase::class)->handle(new CreatePurchaseData(
            null,
            $warehouse->id,
            now()->toDateString(),
            [new PurchaseItemData($product->id, 1, '20.0000')],
            'INFO-001',
            externalAccountingReference: 'QB-200',
        ), $owner);

        $this->actingAs($owner);
        Livewire::test(ViewPurchase::class, ['record' => $purchase->getRouteKey()])
            ->assertSee('No Supplier')
            ->assertSee('QB-200');

        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Approved.', true), $owner);
        $line = $purchase->items()->sole();
        $receipt = app(PurchaseReceivingService::class)->receive($purchase, new ReceivePurchaseData(
            [new PurchaseReceiptItemData($line->id, 1, 0, 0)], now()->toDateTimeString(), (string) Str::uuid(),
        ), $owner);

        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->getRouteKey()])->assertSee('No Supplier');
        $this->assertSame('No Supplier', app(ReceivingHistoryReport::class)->rows()[0]['supplier']);
        $this->assertSame('No Supplier', app(ProductPurchaseCostHistoryReport::class)->rows()[0]['supplier']);
        $this->assertSame('No Supplier', app(SupplierInvoiceRegister::class)->rows()[0]['supplier']);

        $response = app(PurchaseExportService::class)->stream(new PurchaseExportData, $owner);
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('No Supplier', $csv);
        $this->assertStringContainsString('QB-200', $csv);
    }

    public function test_purchase_forms_keep_supplier_optional_and_receive_page_identifies_no_supplier(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $product = Product::factory()->create();

        $this->actingAs($owner);
        Livewire::test(CreatePurchasePage::class)
            ->assertSee('Optional Purchase Details')
            ->fillForm([
                'warehouse_id' => $warehouse->id,
                'purchase_date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $product->id,
                    'ordered_quantity' => 1,
                    'unit_cost' => '10.0000',
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $purchase = Purchase::query()->sole();
        $purchase = app(ApprovePurchase::class)->handle($purchase, new ApprovePurchaseData('Approved.', true), $owner);

        Livewire::test(ReceivePurchasePage::class, ['record' => $purchase->getRouteKey()])
            ->assertSee('No Supplier');
    }

    public function test_no_supplier_label_is_consistent_across_purchase_lists_and_operational_reports(): void
    {
        $owner = $this->owner();
        $warehouse = Warehouse::query()->where('code', 'MAIN')->sole();
        $base = [
            'supplier_id' => null,
            'warehouse_id' => $warehouse->id,
            'created_by_user_id' => $owner->id,
        ];
        $draft = Purchase::factory()->create($base + ['reference' => 'PO-NO-SUPPLIER-DRAFT']);
        Purchase::factory()->create($base + [
            'reference' => 'PO-NO-SUPPLIER-EXPECTED',
            'status' => PurchaseStatus::Approved,
            'expected_delivery_date' => now()->addDay()->toDateString(),
        ]);
        Purchase::factory()->create($base + [
            'reference' => 'PO-NO-SUPPLIER-PARTIAL',
            'status' => PurchaseStatus::PartiallyReceived,
        ]);
        Purchase::factory()->create($base + [
            'reference' => 'PO-NO-SUPPLIER-INVOICE',
            'supplier_invoice_number' => 'INFO-002',
            'supplier_invoice_number_normalized' => 'INFO-002',
        ]);

        $this->actingAs($owner);

        Livewire::test(ListPurchases::class)
            ->assertSee($draft->reference)
            ->assertSee('No Supplier');
        $this->assertContains('No Supplier', collect(app(OpenPurchasesReport::class)->rows())->pluck('supplier'));
        $this->assertSame('No Supplier', app(ExpectedDeliveriesReport::class)->rows()[0]['supplier']);
        $this->assertSame('No Supplier', app(PartiallyReceivedPurchasesReport::class)->rows()[0]['supplier']);
        $this->assertSame('No Supplier', app(SupplierInvoiceRegister::class)->rows()[0]['supplier']);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create();

        return $owner->refresh();
    }
}
