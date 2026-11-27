<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoices\InvoiceVerificationService;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaxInvoiceGoldDesignTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('marketplaces')]
    public function test_customer_facing_marketplace_reference_is_used_without_mutating_the_invoice(?string $platform, string $heading): void
    {
        $invoice = $this->invoice($platform)->fresh();
        $before = $invoice->getRawOriginal();
        $items = $invoice->items->map->getRawOriginal()->all();
        $data = app(TaxInvoiceDocumentService::class)->printViewData($invoice);

        $this->assertSame($heading, $data['orderContext']['heading']);
        $this->assertSame($platform === null ? 'MANUAL-REFERENCE' : 'CUSTOMER-EXTERNAL-123', $data['orderContext']['reference']);
        $this->assertSame($before, $invoice->fresh()->getRawOriginal());
        $this->assertSame($items, $invoice->fresh()->items->map->getRawOriginal()->all());
        $html = view('invoices.tax-invoice', $data)->render();
        $this->assertStringContainsString('Description / Serial Number', $html);
        $this->assertStringContainsString('#ad915d', $html);
        $this->assertStringContainsString($heading, $html);
    }

    public static function marketplaces(): array
    {
        return [
            ['Amazon UAE', 'AMAZON.AE ORDER'], ['Noon', 'NOON ORDER'],
            ['Carrefour UAE', 'CARREFOUR ORDER'], ['Sharaf DG', 'SHARAF DG ORDER'],
            ['Microless', 'MICROLESS ORDER'], ['Other', 'ORDER ID'], [null, 'ORDER ID'],
        ];
    }

    public function test_an_explicit_invoice_reference_is_not_replaced_by_the_current_order_reference(): void
    {
        $invoice = $this->invoice('Amazon', 'EXPLICIT-CUSTOMER-REFERENCE');
        $this->assertSame('EXPLICIT-CUSTOMER-REFERENCE', app(TaxInvoiceDocumentService::class)->printViewData($invoice)['orderContext']['reference']);
    }

    public function test_missing_external_identifier_is_honestly_labelled_as_erp_reference(): void
    {
        $invoice = $this->invoice('Noon');
        $invoice->sourceOrder->update(['external_order_number' => null]);
        $data = app(TaxInvoiceDocumentService::class)->printViewData($invoice->fresh());
        $this->assertSame($invoice->sourceOrder->reference, $data['orderContext']['reference']);
        $this->assertSame('ERP order reference', $data['orderContext']['referenceLabel']);
    }

    public function test_post_issue_external_reference_correction_does_not_rewrite_the_printed_historical_identifier(): void
    {
        $this->freezeTime();
        $invoice = $this->invoice('Amazon');
        $this->travel(2)->seconds();
        $amendment = OrderAmendment::query()->create([
            'order_id' => $invoice->source_order_id, 'amended_by_user_id' => $invoice->created_by_user_id,
            'reason' => 'Correct external reference after issue.', 'window_started_at' => now(),
            'window_expired_at' => now()->addHour(), 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
        ]);
        $amendment->lines()->create(['field' => 'external_order_number', 'old_value' => 'CUSTOMER-EXTERNAL-123', 'new_value' => 'CORRECTED-REFERENCE']);
        $invoice->sourceOrder->update(['external_order_number' => 'CORRECTED-REFERENCE']);
        $this->assertSame('CUSTOMER-EXTERNAL-123', app(TaxInvoiceDocumentService::class)->printViewData($invoice->fresh())['orderContext']['reference']);
    }

    public function test_render_uses_snapshot_assets_and_stored_financials_without_inventing_payment_or_supply_date(): void
    {
        Storage::fake('public');
        $old = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
        Storage::disk('public')->put('old.png', $old);
        $invoice = $this->invoice('Amazon');
        $invoice->sourceOrder->update(['status' => 'fulfilled']);
        CompanyProfile::query()->whereKey(1)->update(['company_name_en' => 'Later company name', 'logo_path' => 'new-golden.png', 'stamp_path' => 'new-stamp.png']);
        $data = app(TaxInvoiceDocumentService::class)->printViewData($invoice->fresh());
        $html = view('invoices.tax-invoice', $data)->render();
        $this->assertSame('data:image/png;base64,'.base64_encode($old), $data['logoDataUri']);
        $this->assertSame($data['logoDataUri'], $data['stampDataUri']);
        $this->assertSame(app(InvoiceVerificationService::class)->url($invoice), $data['verificationUrl']);
        $this->assertStringContainsString('Original company', $html);
        $this->assertStringNotContainsString('Later company name', $html);
        $this->assertStringContainsString('525.00', $html);
        $this->assertStringContainsString('50.00', $html);
        $this->assertStringContainsString('1,050.00', $html);
        $this->assertStringContainsString('Not recorded', $html);
        $this->assertStringContainsString('Not verified', $html);
        $this->assertStringNotContainsString('ISO 9001', $html);
        $this->assertSame('1050.00', $invoice->fresh()->grand_total);
        $this->assertSame('old.png', $invoice->fresh()->seller_snapshot['logo_path']);
    }

    private function invoice(?string $platform, ?string $reference = null): TaxInvoice
    {
        $owner = User::factory()->create();
        Employee::factory()->for($owner)->role(EmployeeRole::Owner)->create(['status' => true]);
        CompanyProfile::query()->create([
            'id' => 1, 'company_name_en' => 'Original company', 'company_name_ar' => 'الشركة الأصلية',
            'trn' => '100000000000001', 'logo_path' => 'old.png', 'stamp_path' => 'old.png',
        ]);
        $order = $platform === null ? null : Order::query()->create([
            'reference' => 'SO-INTERNAL-123', 'external_order_number' => 'CUSTOMER-EXTERNAL-123',
            'marketplace_platform_id' => MarketplacePlatform::factory()->create(['name' => $platform])->id,
            'source' => 'marketplace', 'status' => 'reserved', 'warehouse_id' => Warehouse::factory()->create()->id,
            'order_date' => today()->toDateString(), 'created_by_user_id' => $owner->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return app(TaxInvoiceService::class)->create([
            'source_order_id' => $order?->id, 'order_reference' => $reference ?? $order?->reference ?? 'MANUAL-REFERENCE',
            'customer_name' => 'Invoice customer', 'customer_address' => 'Dubai',
            'invoice_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'HP Series 5 Monitor, 27-inch FHD Display', 'quantity' => 2, 'unit_price_including_vat' => '525.00']],
        ], $owner->refresh());
    }
}
