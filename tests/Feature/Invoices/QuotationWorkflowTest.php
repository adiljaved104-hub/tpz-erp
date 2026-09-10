<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Jobs\SendQuotationEmailJob;
use App\Models\CompanyProfile;
use App\Models\EmailSetting;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Quotation;
use App\Models\Team;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationEmailService;
use App\Services\Quotations\QuotationService;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\ReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class QuotationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_multiple_lines_pricing_and_inventory_isolation(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $a = Product::factory()->create(['selling_price' => '105.00']);
        $b = Product::factory()->create(['selling_price' => '210.00']);
        $before = $this->inventoryFingerprint();
        $quote = $this->createQuote($owner, [
            ['product_id' => $a->id, 'description' => 'Laptop', 'quantity' => 2, 'unit_price_including_vat' => '105.00', 'discount_amount' => '10.00', 'vat_rate' => '5.0000'],
            ['product_id' => $b->id, 'description' => 'Monitor', 'quantity' => 1, 'unit_price_including_vat' => '210.00', 'discount_amount' => '0.00', 'vat_rate' => '5.0000'],
        ]);
        $this->assertSame('QT-'.now()->year.'-000001', $quote->reference);
        $this->assertSame('410.00', $quote->grand_total);
        $this->assertSame('390.48', $quote->subtotal_excluding_vat);
        $this->assertSame('19.52', $quote->vat_amount);
        $this->assertSame('10.00', $quote->discount_total);
        $this->assertCount(2, $quote->items);
        $this->assertSame($before, $this->inventoryFingerprint());
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_lifecycle_edit_and_expiry_rules_are_enforced(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $service = app(QuotationService::class);
        $quote = $this->createQuote($owner, $this->items($product));
        $data = $this->data($this->items($product));
        $data['customer_name'] = 'Updated Customer';
        $service->updateDraft($quote, $data, $owner);
        $this->assertSame('Updated Customer', $quote->refresh()->customer_name);
        $service->transition($quote, QuotationStatus::Sent, $owner);
        $service->transition($quote->refresh(), QuotationStatus::Accepted, $owner);
        $this->assertSame(QuotationStatus::Accepted, $quote->refresh()->status);
        $this->expectException(ValidationException::class);
        $service->updateDraft($quote, $data, $owner);
    }

    public function test_unconverted_draft_displays_expired_after_inclusive_validity_date(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $data = $this->data($this->items($product));
        $data['valid_until'] = today()->toDateString();
        $quote = app(QuotationService::class)->create($data, $owner);
        $this->assertSame(QuotationStatus::Draft, $quote->effectiveStatus());
        $this->travelTo(today()->addDay()->startOfDay());
        $this->assertSame(QuotationStatus::Expired, $quote->refresh()->effectiveStatus());
        $this->assertSame(QuotationStatus::Draft, $quote->status);
    }

    public function test_invoice_conversion_allocates_new_invoice_once_and_preserves_quotation_reference(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $quote = $this->createQuote($owner, $this->items($product));
        $service = app(QuotationService::class);
        $service->transition($quote, QuotationStatus::Sent, $owner);
        $service->transition($quote->refresh(), QuotationStatus::Accepted, $owner);
        $key = (string) Str::uuid();
        $invoice = app(QuotationConversionService::class)->toInvoice($quote->refresh(), $owner, $key);
        $again = app(QuotationConversionService::class)->toInvoice($quote->refresh(), $owner, $key);
        $this->assertSame($invoice->id, $again->id);
        $this->assertStringStartsWith('TP-INV', $invoice->invoice_number);
        $this->assertSame('QT-'.now()->year.'-000001', $quote->refresh()->reference);
        $this->assertSame($invoice->id, $quote->tax_invoice_id);
        $this->assertSame(QuotationStatus::Converted, $quote->status);
        $this->assertDatabaseCount('tax_invoices', 1);
    }

    public function test_scope_ui_and_pdf_are_authorized(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $mine = $this->createQuote($staff, $this->items($product));
        $hidden = $this->createQuote($other, $this->items($product));
        $ids = app(QuotationAuthorization::class)->scope(Quotation::query(), $staff)->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->actingAs($staff)->get(QuotationResource::getUrl('index'))->assertOk();
        $this->actingAs($staff)->get(QuotationResource::getUrl('view', ['record' => $mine]))->assertOk();
        $this->actingAs($staff)->get(QuotationResource::getUrl('view', ['record' => $hidden]))->assertNotFound();
        $this->actingAs($staff)->get(route('quotations.pdf', $mine))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($staff)->get(route('quotations.pdf', $hidden))->assertForbidden();
        $this->actingAs($staff)->get(route('quotations.pdf', ['quotation' => $mine, 'print' => 1]))->assertOk()->assertSee($mine->reference)->assertSee('QUOTATION');
        Livewire::actingAs($staff)->test(ListQuotations::class)->assertSuccessful();
        Livewire::actingAs($staff)->test(CreateQuotation::class)->assertSuccessful();
        Livewire::actingAs($staff)->test(ViewQuotation::class, ['record' => $mine->getRouteKey()])->assertSuccessful()->assertActionVisible('edit')->assertActionVisible('pdf')->assertActionVisible('print')->assertActionVisible('whatsapp')->assertActionVisible('email')->assertActionVisible('markSent');
    }

    public function test_manager_team_scope_and_staff_own_scope_are_sql_enforced(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $team = Team::query()->create(['name' => 'Sales A', 'status' => true]);
        $otherTeam = Team::query()->create(['name' => 'Sales B', 'status' => true]);
        $manager = $this->user(EmployeeRole::Manager);
        $manager->employee->update(['team_id' => $team->id]);
        $mine = $this->user(EmployeeRole::Staff);
        $mine->employee->update(['team_id' => $team->id]);
        $outside = $this->user(EmployeeRole::Staff);
        $outside->employee->update(['team_id' => $otherTeam->id]);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $teamQuote = $this->createQuote($mine, $this->items($product));
        $outsideQuote = $this->createQuote($outside, $this->items($product));
        $authorization = app(QuotationAuthorization::class);
        $this->assertSame([$teamQuote->id], $authorization->scope(Quotation::query(), $manager->refresh())->pluck('id')->all());
        $this->assertSame([$teamQuote->id], $authorization->scope(Quotation::query(), $mine->refresh())->pluck('id')->all());
        $this->assertNotContains($outsideQuote->id, $authorization->scope(Quotation::query(), $manager)->pluck('id')->all());
    }

    public function test_order_conversion_uses_existing_inventory_workflow_only_at_conversion(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $warehouse = Warehouse::factory()->create(['is_default' => true]);
        ProductInventory::factory()->for($product)->for($warehouse)->create(['available_quantity' => 5, 'reserved_quantity' => 0, 'average_cost' => '50.0000']);
        $quote = $this->createQuote($owner, $this->items($product));
        $this->assertDatabaseCount('inventory_reservations', 0);
        $flow = app(QuotationService::class);
        $flow->transition($quote, QuotationStatus::Sent, $owner);
        $flow->transition($quote->refresh(), QuotationStatus::Accepted, $owner);
        $order = app(QuotationConversionService::class)->toOrder($quote->refresh(), $warehouse->id, $owner, (string) Str::uuid());
        $this->assertSame($order->id, $quote->refresh()->order_id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertGreaterThan(0, DB::table('stock_movements')->count());
        $this->assertSame('Acme Customer', $order->customer_name);
        $this->assertSame('+971500000000', $order->customer_phone);
        $this->assertSame('other', $order->web_sales_channel->value);
        $this->assertSame('shop_pickup', $order->delivery_type->value);
        $again = app(QuotationConversionService::class)->toOrder($quote->refresh(), $warehouse->id, $owner);
        $this->assertSame($order->id, $again->id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_convert_action_collects_missing_customer_phone_without_mutating_quotation_and_reuses_reserved_key(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $warehouse = Warehouse::factory()->create(['is_default' => true]);
        ProductInventory::factory()->for($product)->for($warehouse)->create([
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'average_cost' => '50.0000',
        ]);
        $data = $this->data($this->items($product));
        $data['customer_phone'] = null;
        $quote = app(QuotationService::class)->create($data, $owner);
        $service = app(QuotationService::class);
        $service->transition($quote, QuotationStatus::Sent, $owner);
        $service->transition($quote->refresh(), QuotationStatus::Accepted, $owner);
        $reservedKey = (string) Str::uuid();
        $quote->forceFill(['order_conversion_idempotency_key' => $reservedKey])->save();

        Livewire::actingAs($owner)->test(ViewQuotation::class, ['record' => $quote->id])
            ->callAction('convertOrder', [
                'warehouse_id' => $warehouse->id,
                'customer_phone' => '+971501234567',
            ])->assertHasNoActionErrors();
        $order = $quote->refresh()->order;
        $retry = app(QuotationConversionService::class)->toOrder($quote->refresh(), $warehouse->id, $owner);

        $this->assertSame($reservedKey, $order->idempotency_key);
        $this->assertSame($order->id, $retry->id);
        $this->assertSame('Acme Customer', $order->customer_name);
        $this->assertSame('+971501234567', $order->customer_phone);
        $this->assertSame('other', $order->web_sales_channel->value);
        $this->assertSame('shop_pickup', $order->delivery_type->value);
        $this->assertNull($quote->refresh()->customer_phone);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_convert_to_order_action_receives_submitted_warehouse_id(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $warehouse = Warehouse::factory()->create(['is_default' => true]);
        ProductInventory::factory()->for($product)->for($warehouse)->create([
            'available_quantity' => 5,
            'reserved_quantity' => 0,
            'average_cost' => '50.0000',
        ]);
        $quote = $this->createQuote($owner, $this->items($product));
        $service = app(QuotationService::class);
        $service->transition($quote, QuotationStatus::Sent, $owner);
        $service->transition($quote->refresh(), QuotationStatus::Accepted, $owner);

        Livewire::actingAs($owner)->test(ViewQuotation::class, ['record' => $quote->id])
            ->callAction('convertOrder', ['warehouse_id' => $warehouse->id])
            ->assertHasNoActionErrors();

        $this->assertSame($warehouse->id, $quote->refresh()->order->warehouse_id);
    }

    public function test_reject_action_receives_submitted_reason(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $quote = $this->createQuote($owner, $this->items($product));
        app(QuotationService::class)->transition($quote, QuotationStatus::Sent, $owner);

        Livewire::actingAs($owner)->test(ViewQuotation::class, ['record' => $quote->id])
            ->callAction('reject', ['reason' => 'Customer selected another offer.'])
            ->assertHasNoActionErrors();

        $this->assertSame(QuotationStatus::Rejected, $quote->refresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'quotation.rejected',
            'subject_id' => $quote->id,
            'description' => 'Customer selected another offer.',
        ]);
    }

    public function test_cancel_action_receives_submitted_reason(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $quote = $this->createQuote($owner, $this->items($product));

        Livewire::actingAs($owner)->test(ViewQuotation::class, ['record' => $quote->id])
            ->callAction('cancel', ['reason' => 'Customer withdrew the request.'])
            ->assertHasNoActionErrors();

        $this->assertSame(QuotationStatus::Cancelled, $quote->refresh()->status);
        $this->assertDatabaseHas('activity_logs', [
            'event' => 'quotation.cancelled',
            'subject_id' => $quote->id,
            'description' => 'Customer withdrew the request.',
        ]);
    }

    public function test_failed_order_conversion_leaves_quotation_unconverted_and_reuses_reserved_key(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $warehouse = Warehouse::factory()->create();
        ProductInventory::factory()->for($product)->for($warehouse)->create(['available_quantity' => 0]);
        $quote = $this->createQuote($owner, $this->items($product));
        $flow = app(QuotationService::class);
        $flow->transition($quote, QuotationStatus::Sent, $owner);
        $flow->transition($quote->refresh(), QuotationStatus::Accepted, $owner);
        $key = (string) Str::uuid();
        try {
            app(QuotationConversionService::class)->toOrder($quote->refresh(), $warehouse->id, $owner, $key);
            $this->fail('Stock validation should fail.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $quote->refresh();
        $this->assertSame(QuotationStatus::Accepted, $quote->status);
        $this->assertNull($quote->order_id);
        $this->assertSame($key, $quote->order_conversion_idempotency_key);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_report_provider_registers_all_quotation_reports(): void
    {
        $owner = $this->owner();
        $this->profile($owner);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $this->createQuote($owner, $this->items($product));
        $keys = collect(app(ReportCatalog::class)->available($owner))->keys()->filter(fn ($key) => str_contains($key, 'quotation'))->values()->all();
        $this->assertEqualsCanonicalizing(['sales.quotations', 'sales.quotations_by_status', 'sales.quotations_by_employee', 'sales.accepted_converted_quotations', 'sales.quotation_value_summary'], $keys);
        $report = app(ReportQueryService::class)->run($owner, 'sales.quotations', ['from' => today()->toDateString(), 'to' => today()->toDateString()]);
        $this->assertSame(1, $report->totalRows);
        $this->assertSame(105.0, $report->summary['Quotation Value']);
        $this->assertSame('QT-'.now()->year.'-000001', $report->rows->first()['reference']);
        $filters = ['from' => today()->toDateString(), 'to' => today()->toDateString()];
        $exports = app(ReportExportService::class);
        $this->assertStringStartsWith('%PDF', (string) $exports->export($owner, 'sales.quotations', 'pdf', $filters)->getContent());
        $this->assertFileExists($exports->export($owner, 'sales.quotations', 'xlsx', $filters)->getFile()->getPathname());
        ob_start();
        $exports->export($owner, 'sales.quotations', 'csv', $filters)->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('QT-'.now()->year.'-000001', $csv);
    }

    public function test_email_is_queued_once_with_delivery_history_and_no_live_send(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $this->profile($owner);
        EmailSetting::query()->create(['id' => 1, 'enabled' => true, 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'encryption' => 'tls', 'smtp_username' => 'mailer@example.test', 'smtp_password_encrypted' => 'test-secret', 'from_email' => 'sales@example.test', 'from_name' => 'TPZ Sales', 'updated_by_user_id' => $owner->id]);
        $product = Product::factory()->create(['selling_price' => '105.00']);
        $quote = $this->createQuote($owner, $this->items($product));
        $key = (string) Str::uuid();
        $delivery = app(QuotationEmailService::class)->queue($quote, 'buyer@example.test', $owner, $key);
        $again = app(QuotationEmailService::class)->queue($quote->refresh(), 'buyer@example.test', $owner, $key);
        $this->assertSame($delivery->id, $again->id);
        $this->assertDatabaseCount('quotation_email_deliveries', 1);
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(QuotationStatus::Sent, $quote->refresh()->status);
        Queue::assertPushed(SendQuotationEmailJob::class, 1);
    }

    private function createQuote(User $actor, array $items): Quotation
    {
        return app(QuotationService::class)->create($this->data($items), $actor);
    }

    private function data(array $items): array
    {
        $warehouse = Warehouse::query()->latest('id')->first() ?? Warehouse::factory()->create();

        return ['warehouse_id' => $warehouse->id] + $this->documentData($items);
    }

    private function documentData(array $items): array
    {
        return ['document_type' => 'quotation', 'quotation_date' => today()->toDateString(), 'valid_until' => today()->addDays(14)->toDateString(), 'customer_name' => 'Acme Customer', 'customer_company' => 'Acme', 'customer_phone' => '+971500000000', 'customer_email' => 'buyer@example.test', 'customer_address' => 'Dubai UAE', 'customer_trn' => null, 'external_reference' => 'RFQ-1', 'notes' => 'Valid while stocks last.', 'idempotency_key' => (string) Str::uuid(), 'items' => $items];
    }

    private function items(Product $p): array
    {
        return [['product_id' => $p->id, 'description' => $p->name, 'quantity' => 1, 'unit_price_including_vat' => '105.00', 'discount_amount' => '0.00', 'vat_rate' => '5.0000']];
    }

    private function owner(): User
    {
        return $this->user(EmployeeRole::Owner);
    }

    private function user(EmployeeRole $role): User
    {
        $u = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($u)->role($role)->create(['email' => $u->email, 'status' => true]);

        return $u->refresh();
    }

    private function profile(User $owner): void
    {
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Tech Point Zone', 'company_name_ar' => 'تك بوينت زون', 'trn' => '100000000000001', 'address_en' => 'Dubai UAE', 'legal_statement_en' => 'Registered company.', 'updated_by_user_id' => $owner->id]);
    }

    private function inventoryFingerprint(): array
    {
        return ['inventories' => DB::table('product_inventories')->count(), 'reservations' => DB::table('inventory_reservations')->count(), 'movements' => DB::table('stock_movements')->count()];
    }
}
