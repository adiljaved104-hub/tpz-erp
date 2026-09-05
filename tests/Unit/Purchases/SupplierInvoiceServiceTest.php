<?php

namespace Tests\Unit\Purchases;

use App\Services\Purchases\SupplierInvoiceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupplierInvoiceServiceTest extends TestCase
{
    public function test_supplierless_invoice_has_no_supplier_scoped_conflict(): void
    {
        $this->assertNull((new SupplierInvoiceService)->conflictingPurchase(null, 'INV-001'));
    }

    #[DataProvider('invoiceVariants')]
    public function test_it_normalizes_approved_invoice_variants(string $invoice): void
    {
        $this->assertSame('INV-001', (new SupplierInvoiceService)->normalize($invoice));
    }

    public static function invoiceVariants(): array
    {
        return [['inv / 001'], ['INV-001'], ['inv_001'], ['INV\\001'], ['INV   001'], ['--INV__//001--']];
    }

    public function test_it_preserves_other_meaningful_characters_and_allows_null(): void
    {
        $service = new SupplierInvoiceService;

        $this->assertSame('INV#001', $service->normalize(' inv#001 '));
        $this->assertNull($service->normalize(null));
        $this->assertNull($service->normalize('  ---  '));
    }
}
