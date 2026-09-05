<?php

namespace Tests\Feature\Invoices;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuotationFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_is_additive_empty_and_initializes_an_unconsumed_sequence(): void
    {
        foreach (['quotations', 'quotation_items', 'quotation_email_deliveries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertTrue(Schema::hasColumns('quotations', [
            'reference', 'document_type', 'status', 'quotation_date', 'valid_until',
            'customer_name', 'customer_company', 'customer_phone', 'customer_email',
            'customer_address', 'customer_trn', 'external_reference', 'currency',
            'subtotal_excluding_vat', 'discount_total', 'vat_amount', 'grand_total',
            'seller_snapshot', 'terms_en_snapshot', 'terms_ar_snapshot', 'notes',
            'salesperson_employee_id', 'created_by_user_id', 'order_id', 'tax_invoice_id',
            'idempotency_key', 'order_conversion_idempotency_key',
            'invoice_conversion_idempotency_key', 'sent_at', 'accepted_at', 'rejected_at',
            'converted_at', 'cancelled_at',
        ]));
        $this->assertTrue(Schema::hasColumns('quotation_items', [
            'quotation_id', 'product_id', 'sku', 'product_name', 'brand_name', 'model_name',
            'description', 'quantity', 'unit_price_including_vat', 'discount_amount',
            'vat_rate', 'subtotal_excluding_vat', 'vat_amount', 'total_including_vat', 'line_number',
        ]));
        $this->assertTrue(Schema::hasColumns('quotation_email_deliveries', [
            'quotation_id', 'recipient_email', 'subject', 'status', 'requested_at', 'sent_at',
            'failed_at', 'safe_error_code', 'safe_error_message', 'idempotency_key', 'requested_by_user_id',
        ]));

        $this->assertDatabaseHas('reference_sequences', ['key' => 'quotation:2026', 'next_value' => 1]);
        $this->assertDatabaseMissing('quotations', ['reference' => 'QT-2026-000001']);
    }

    public function test_controlled_values_date_money_conversion_and_line_constraints_are_enforced(): void
    {
        [$user, $employee] = $this->actor();

        $this->expectQueryFailure(fn () => DB::table('quotations')->insert($this->quotation($user, $employee, [
            'document_type' => 'invoice',
        ])));
        $this->expectQueryFailure(fn () => DB::table('quotations')->insert($this->quotation($user, $employee, [
            'valid_until' => '2026-08-28',
        ])));
        $this->expectQueryFailure(fn () => DB::table('quotations')->insert($this->quotation($user, $employee, [
            'status' => 'converted',
        ])));

        $quotationId = DB::table('quotations')->insertGetId($this->quotation($user, $employee));
        $product = Product::factory()->create();
        $this->expectQueryFailure(fn () => DB::table('quotation_items')->insert([
            'quotation_id' => $quotationId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'description' => $product->name,
            'quantity' => 1,
            'unit_price_including_vat' => 100,
            'discount_amount' => 100,
            'vat_rate' => 5,
            'subtotal_excluding_vat' => 95.24,
            'vat_amount' => 4.76,
            'total_including_vat' => 100,
            'line_number' => 1,
        ]));
    }

    public function test_foreign_keys_indexes_and_mysql_safe_migration_conventions_are_present(): void
    {
        $quotationForeignTables = collect(DB::select("PRAGMA foreign_key_list('quotations')"))->pluck('table')->all();
        $this->assertContains('users', $quotationForeignTables);
        $this->assertContains('employees', $quotationForeignTables);
        $this->assertContains('orders', $quotationForeignTables);
        $this->assertContains('tax_invoices', $quotationForeignTables);
        $this->assertContains('products', collect(DB::select("PRAGMA foreign_key_list('quotation_items')"))->pluck('table')->all());

        $quotationIndexes = collect(DB::select("PRAGMA index_list('quotations')"))->pluck('name')->all();
        foreach (['quotations_status_date_idx', 'quotations_sales_status_valid_idx', 'quotations_creator_date_idx', 'quotations_type_date_idx'] as $index) {
            $this->assertContains($index, $quotationIndexes);
        }

        $migration = file_get_contents(database_path('migrations/2026_08_29_090000_create_quotations_foundation.php'));
        $this->assertStringContainsString("DB::getDriverName() === 'sqlite'", $migration);
        $this->assertStringContainsString("decimal('grand_total', 15, 2)", $migration);
        $this->assertStringContainsString('restrictOnDelete()', $migration);
        $this->assertStringNotContainsString('float(', strtolower($migration));
        $this->assertStringNotContainsString('double(', strtolower($migration));
        $this->assertStringNotContainsString('cascadeOnDelete', $migration);
    }

    /** @return array{User, Employee} */
    private function actor(): array
    {
        $user = User::factory()->create();
        $employee = Employee::factory()->for($user)->role(EmployeeRole::Owner)->create(['email' => $user->email]);

        return [$user, $employee];
    }

    private function quotation(User $user, Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'reference' => 'QT-2026-'.fake()->unique()->numerify('######'),
            'document_type' => 'quotation',
            'status' => 'draft',
            'quotation_date' => '2026-08-29',
            'valid_until' => '2026-09-12',
            'customer_name' => 'Quotation Customer',
            'currency' => 'AED',
            'subtotal_excluding_vat' => 0,
            'discount_total' => 0,
            'vat_amount' => 0,
            'grand_total' => 0,
            'salesperson_employee_id' => $employee->id,
            'created_by_user_id' => $user->id,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function expectQueryFailure(\Closure $callback): void
    {
        try {
            $callback();
            $this->fail('A database constraint failure was expected.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
