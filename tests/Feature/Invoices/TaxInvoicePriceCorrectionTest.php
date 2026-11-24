<?php

namespace Tests\Feature\Invoices;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\TaxInvoice;
use App\Models\TaxInvoicePriceCorrection;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoicePriceCorrectionService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoicePriceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_corrects_only_prices_with_original_vat_and_document_immutability(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);
        $original = $invoice->getRawOriginal();
        $oldItems = $invoice->items()->get()->map->getRawOriginal()->all();

        $corrected = app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice, '630.00'), $owner);

        $this->assertNotSame($invoice->id, $corrected->id);
        $this->assertNotSame($invoice->invoice_number, $corrected->invoice_number);
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('issued', $corrected->status);
        $this->assertSame('630.00', $corrected->items->first()->unit_price_including_vat);
        $this->assertSame('1260.00', $corrected->grand_total);
        $this->assertSame('1200.00', $corrected->subtotal_excluding_vat);
        $this->assertSame('60.00', $corrected->vat_amount);
        $this->assertSame($invoice->vat_rate, $corrected->vat_rate);
        $this->assertSame($invoice->seller_snapshot, $corrected->seller_snapshot);
        $this->assertSame($invoice->items()->first()->description, $corrected->items->first()->description);
        $this->assertSame($invoice->items()->first()->quantity, $corrected->items->first()->quantity);
        $this->assertSame($oldItems, $invoice->items()->get()->map->getRawOriginal()->all());
        $this->assertSame($original['invoice_number'], $invoice->fresh()->invoice_number);
        $this->assertSame($original['grand_total'], $invoice->fresh()->getRawOriginal('grand_total'));

        $link = TaxInvoicePriceCorrection::query()->sole();
        $this->assertSame($invoice->id, $link->original_invoice_id);
        $this->assertSame($corrected->id, $link->replacement_invoice_id);
        $this->assertSame($owner->id, $link->actor_user_id);
        $this->assertSame('Price entered incorrectly.', $link->reason);
    }

    public function test_permission_and_invoice_scope_are_enforced(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $mine = $this->invoice($staff);
        $theirs = $this->invoice($other);

        $this->assertFalse(app(InvoiceAuthorization::class)->allows($staff, InvoicePermission::EditPrices, $mine));
        try {
            app(TaxInvoicePriceCorrectionService::class)->replace($mine, $this->change($mine), $staff);
            $this->fail('An employee without the independent grant replaced the Invoice.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->allow($staff, $owner);
        $this->assertTrue(app(InvoiceAuthorization::class)->allows($staff, InvoicePermission::EditPrices, $mine));
        $this->expectException(AuthorizationException::class);
        app(TaxInvoicePriceCorrectionService::class)->replace($theirs, $this->change($theirs), $staff);
    }

    public function test_duplicate_or_noop_correction_is_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);
        try {
            app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice, '525.00'), $owner);
            $this->fail('No-op price correction was accepted.');
        } catch (ValidationException) {
            $this->assertSame('issued', $invoice->fresh()->status);
        }

        app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice), $owner);
        $this->expectException(ValidationException::class);
        app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice), $owner);
    }

    public function test_browser_action_is_hidden_without_independent_permission(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $invoice = $this->invoice($staff);

        Livewire::actingAs($staff)->test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('correctPrices');

        $this->allow($staff, $owner);
        Livewire::actingAs($staff)->test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('correctPrices');

        app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice), $staff);
        Livewire::actingAs($staff)->test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('correctPrices')
            ->assertActionVisible('priceCorrectionHistory');
    }

    private function change(TaxInvoice $invoice, string $price = '630.00'): array
    {
        return [
            'reason' => 'Price entered incorrectly.',
            'items' => $invoice->items()->get()->map(fn ($item): array => [
                'id' => $item->id,
                'unit_price_including_vat' => $price,
            ])->all(),
        ];
    }

    private function invoice(User $actor): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Original Customer',
            'customer_address' => 'Dubai, UAE',
            'customer_trn' => null,
            'order_reference' => 'ORDER-CORRECTION-'.Str::random(8),
            'invoice_date' => '2026-11-24',
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 2, 'unit_price_including_vat' => '525.00']],
        ], $actor);
    }

    private function allow(User $staff, User $owner): void
    {
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->employee->id,
            'permission_key' => InvoicePermission::EditPrices->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Invoice price correction test.',
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($staff->employee->id);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function profile(User $owner): void
    {
        app(CompanyProfileService::class)->save([
            'company_name_en' => 'Tech Point Zone',
            'company_name_ar' => 'تك بوينت زون',
            'trn' => '100000000000001',
            'trade_license_number' => 'TL-1',
            'ded_registration_number' => 'DED-1',
            'address_en' => 'Dubai, UAE',
            'address_ar' => 'دبي، الإمارات',
            'phone' => '+9714000000',
            'mobile' => '+9715000000',
            'email' => 'accounts@example.test',
            'legal_statement_en' => 'Registered company statement.',
            'legal_statement_ar' => 'بيان الشركة المسجلة.',
        ], $owner);
    }
}
