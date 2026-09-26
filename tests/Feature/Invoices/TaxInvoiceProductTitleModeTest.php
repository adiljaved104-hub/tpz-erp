<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\ProductTitleMode;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductMarketplaceListing;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaxInvoiceProductTitleModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_marketplace_website_accounting_and_custom_modes_resolve_expected_snapshots(): void
    {
        $owner = $this->owner();
        $product = Product::factory()->create([
            'name' => 'Legacy internal name', 'model' => 'Victus 15', 'processor_class' => 'Core i5',
            'processor_model' => 'i5-13420H', 'processor_generation' => '13th Gen', 'ram' => '16GB',
            'storage' => '512GB SSD', 'graphics' => 'RTX 4050', 'color' => 'Blue', 'screen_size' => '15.6"',
        ]);
        $platform = MarketplacePlatform::factory()->create();
        ProductMarketplaceListing::query()->create(['product_id' => $product->id, 'marketplace_platform_id' => $platform->id, 'listing_title' => 'Amazon approved Victus listing title']);
        [$marketplaceOrder] = $this->order($owner, $product, $platform->id, 'other');
        [$websiteOrder] = $this->order($owner, $product, null, 'website');
        [$accountsOrder] = $this->order($owner, $product);
        $imports = app(TaxInvoiceOrderImportService::class);

        $this->assertSame('Amazon approved Victus listing title', $imports->prefill($owner, $marketplaceOrder->id)['items'][0]['description']);
        $this->assertStringContainsString('Laptop – i5-13420H', $imports->prefill($owner, $websiteOrder->id)['items'][0]['description']);
        $this->assertStringContainsString('16GB/512GB SSD', $imports->prefill($owner, $accountsOrder->id, ProductTitleMode::Accounting)['items'][0]['description']);
        $this->assertSame('Legacy internal name', $imports->prefill($owner, $accountsOrder->id, ProductTitleMode::Custom)['items'][0]['description']);
    }

    public function test_issued_description_is_immutable_and_invoice_creation_does_not_move_stock(): void
    {
        $owner = $this->owner();
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Tech Point Zone', 'trn' => '100000000000001', 'address_en' => 'Dubai', 'legal_statement_en' => 'Registered.', 'updated_by_user_id' => $owner->id]);
        $product = Product::factory()->create(['name' => 'Internal laptop', 'model' => 'Model X', 'processor_class' => 'Core i7', 'ram' => '16GB', 'storage' => '1TB SSD']);
        [$order] = $this->order($owner, $product, null, 'website');
        $prefill = app(TaxInvoiceOrderImportService::class)->prefill($owner, $order->id, ProductTitleMode::Website);
        $movementCount = StockMovement::query()->count();
        $invoice = app(TaxInvoiceService::class)->create([
            'title_mode' => ProductTitleMode::Website->value, 'source_order_id' => $order->id,
            'customer_name' => 'Customer', 'customer_address' => 'Dubai', 'order_reference' => $order->reference,
            'invoice_date' => today()->toDateString(), 'idempotency_key' => (string) Str::uuid(), 'items' => $prefill['items'],
        ], $owner);
        $snapshot = $invoice->items->sole()->description;

        DB::table('products')->where('id', $product->id)->update(['website_title_override' => 'Later changed website title']);
        $this->assertSame($snapshot, $invoice->refresh()->items->sole()->description);
        $this->assertSame($movementCount, StockMovement::query()->count());
    }

    /** @return array{Order, OrderItem} */
    private function order(User $owner, Product $product, ?int $platformId = null, ?string $channel = null): array
    {
        $order = Order::query()->create([
            'reference' => 'SO-'.Str::upper(Str::random(8)), 'source' => $platformId ? 'marketplace' : 'manual', 'status' => 'draft',
            'warehouse_id' => Warehouse::factory()->create()->id, 'marketplace_platform_id' => $platformId, 'web_sales_channel' => $channel,
            'external_order_number' => 'EXT-'.Str::upper(Str::random(8)), 'order_date' => today(), 'customer_name' => 'Customer',
            'customer_phone' => '+971500000000', 'subtotal' => '100.00', 'discount_total' => '0.00', 'vat_total' => '5.00',
            'grand_total' => '105.00', 'idempotency_key' => (string) Str::uuid(), 'created_by_user_id' => $owner->id,
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'ordered_quantity' => 1, 'selling_price' => '100.00', 'discount_total' => '0.00', 'vat_rate' => '5.0000',
            'vat_amount' => '5.00', 'line_total' => '105.00',
        ]);

        return [$order, $item];
    }

    private function owner(): User
    {
        $email = Str::lower(Str::random(10)).'@techpointzone.com';
        $user = User::factory()->create(['email' => $email]);
        Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $email, 'status' => true]);

        return $user->refresh();
    }
}
