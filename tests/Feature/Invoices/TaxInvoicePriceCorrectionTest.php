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
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoicePriceCorrectionService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxInvoicePriceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_request_review_without_voiding_or_reissuing_the_original(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);
        $original = (array) DB::table('tax_invoices')->where('id', $invoice->id)->first();
        $items = $invoice->items()->get()->map->getRawOriginal()->all();

        $proposal = app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice, '630.00'), $owner);

        $this->assertSame('pending_review', $proposal->status);
        $this->assertSame($invoice->id, $proposal->tax_invoice_id);
        $this->assertSame('1050.00', $proposal->old_gross);
        $this->assertSame('1260.00', $proposal->new_gross);
        $this->assertSame('210.00', $proposal->gross_difference);
        $this->assertSame('210.00', $proposal->adjustment_documents_preview['additional_tax_invoice']['gross']);
        $this->assertSame('10.00', $proposal->adjustment_documents_preview['additional_tax_invoice']['vat']);
        $this->assertEmpty($proposal->adjustment_documents_preview['tax_credit_note']['lines']);
        $this->assertSame($original, (array) DB::table('tax_invoices')->where('id', $invoice->id)->first());
        $this->assertSame($items, $invoice->items()->get()->map->getRawOriginal()->all());
        $this->assertSame(1, TaxInvoice::query()->count());
    }

    public function test_decreased_price_creates_credit_note_preview_without_modifying_invoice(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);

        $proposal = app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice, '420.00'), $owner);

        $this->assertSame('-210.00', $proposal->gross_difference);
        $this->assertSame('210.00', $proposal->adjustment_documents_preview['tax_credit_note']['gross']);
        $this->assertSame('10.00', $proposal->adjustment_documents_preview['tax_credit_note']['vat']);
        $this->assertEmpty($proposal->adjustment_documents_preview['additional_tax_invoice']['lines']);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_legacy_void_and_reissue_correction_is_disabled(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);

        $this->expectException(ValidationException::class);
        app(TaxInvoicePriceCorrectionService::class)->replace($invoice, $this->change($invoice), $owner);
    }

    public function test_independent_permission_and_invoice_scope_are_enforced(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $staff = $this->user(EmployeeRole::Staff);
        $other = $this->user(EmployeeRole::Staff);
        $this->profile($owner);
        $mine = $this->invoice($staff);
        $theirs = $this->invoice($other);

        $this->assertFalse(app(InvoiceAuthorization::class)->allows($staff, InvoicePermission::EditPrices, $mine));
        try {
            app(TaxInvoicePriceCorrectionService::class)->propose($mine, $this->change($mine), $staff);
            $this->fail('Unprivileged staff submitted a price proposal.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->allow($staff, $owner);
        $this->assertTrue(app(InvoiceAuthorization::class)->allows($staff, InvoicePermission::EditPrices, $mine));
        $this->expectException(AuthorizationException::class);
        app(TaxInvoicePriceCorrectionService::class)->propose($theirs, $this->change($theirs), $staff);
    }

    public function test_noop_and_second_pending_request_are_blocked(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);
        try {
            app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice, '525.00'), $owner);
            $this->fail('No-op proposal accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, TaxInvoicePriceAdjustmentProposal::query()->count());
        }

        app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice), $owner);
        $this->expectException(ValidationException::class);
        app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice), $owner);
    }

    public function test_proposal_is_append_only(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $this->profile($owner);
        $invoice = $this->invoice($owner);
        $proposal = app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice), $owner);
        $this->expectException(\LogicException::class);
        $proposal->update(['reason' => 'Edited']);
    }

    public function test_browser_action_is_hidden_without_independent_permission_or_with_pending_request(): void
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

        app(TaxInvoicePriceCorrectionService::class)->propose($invoice, $this->change($invoice), $staff);
        Livewire::actingAs($staff)->test(ViewTaxInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('correctPrices')
            ->assertActionVisible('priceAdjustmentRequests');
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
