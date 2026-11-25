<?php

namespace Tests\Feature\Invoices;

use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPeriodStatus;
use App\Enums\EmployeeRole;
use App\Models\AccountingBook;
use App\Models\AccountingPeriod;
use App\Models\Employee;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceAdjustmentDocument;
use App\Models\User;
use App\Services\Accounting\AccountingLedgerService;
use App\Services\CompanyProfileService;
use App\Services\Invoices\TaxInvoiceAdjustmentIssuanceService;
use App\Services\Invoices\TaxInvoicePriceCorrectionService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxInvoiceAdjustmentIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_is_fail_closed_by_default_even_after_finance_approval(): void
    {
        $actor = $this->owner();
        $invoice = $this->invoice($actor);
        $proposal = $this->approved($invoice, $actor, '630.00');

        $this->expectException(ValidationException::class);
        app(TaxInvoiceAdjustmentIssuanceService::class)->issue($proposal, [], $actor);
    }

    public function test_approved_price_increase_posts_additional_invoice_journal_and_preserves_original(): void
    {
        $actor = $this->owner();
        $invoice = $this->invoice($actor);
        $original = $invoice->getRawOriginal('grand_total');
        $proposal = $this->approved($invoice, $actor, '630.00');
        [$book, $input, $originalJournal] = $this->prepareLedger($invoice, $actor);
        config()->set('tax_invoice_adjustments.issuance_enabled', true);
        config()->set('tax_invoice_adjustments.electronic_delivery_ready', true);

        $documents = app(TaxInvoiceAdjustmentIssuanceService::class)->issue($proposal, $input, $actor);

        $this->assertCount(1, $documents);
        $document = $documents->first();
        $this->assertSame('additional_tax_invoice', $document->kind);
        $this->assertStringStartsWith('TPZ-ADI-', $document->document_number);
        $this->assertSame('200.00', $document->net_amount);
        $this->assertSame('10.00', $document->vat_amount);
        $this->assertSame('210.00', $document->gross_amount);
        $this->assertSame($originalJournal->id, $document->original_accounting_journal_id);
        $this->assertSame(AccountingJournalStatus::Posted, $document->accountingJournal->status);
        $this->assertSame($original, $invoice->fresh()->getRawOriginal('grand_total'));
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(1, TaxInvoiceAdjustmentDocument::query()->count());
        $this->assertSame(1, TaxInvoice::query()->count());
        $this->assertCount(2, $book->journals);
    }

    public function test_credit_note_posts_reducing_vat_and_receivables_without_editing_invoice(): void
    {
        $actor = $this->owner();
        $invoice = $this->invoice($actor);
        $proposal = $this->approved($invoice, $actor, '420.00');
        [, $input] = $this->prepareLedger($invoice, $actor);
        config()->set('tax_invoice_adjustments.issuance_enabled', true);
        config()->set('tax_invoice_adjustments.electronic_delivery_ready', true);

        $doc = app(TaxInvoiceAdjustmentIssuanceService::class)->issue($proposal, $input, $actor)->sole();

        $this->assertSame('tax_credit_note', $doc->kind);
        $this->assertSame('210.00', $doc->gross_amount);
        $this->assertSame('800.00', $doc->corrected_invoice_net);
        $this->assertSame(AccountingJournalStatus::Posted, $doc->accountingJournal->status);
        $this->assertSame('210.00', $doc->accountingJournal->lines->where('account_id', $input['receivable_account_id'])->first()->credit);
        $this->assertSame('50.00', $invoice->fresh()->vat_amount);
    }

    public function test_closed_original_accounting_period_blocks_issuance_and_journal_posting(): void
    {
        $actor = $this->owner();
        $invoice = $this->invoice($actor);
        $proposal = $this->approved($invoice, $actor, '420.00');
        [$book, $input] = $this->prepareLedger($invoice, $actor);
        AccountingPeriod::query()->where('accounting_book_id', $book->id)
            ->update(['status' => AccountingPeriodStatus::Closed->value]);
        config()->set('tax_invoice_adjustments.issuance_enabled', true);
        config()->set('tax_invoice_adjustments.electronic_delivery_ready', true);
        try {
            app(TaxInvoiceAdjustmentIssuanceService::class)->issue($proposal, $input, $actor);
            $this->fail('Issuance entered a closed period.');
        } catch (ValidationException) {
            $this->assertSame(0, TaxInvoiceAdjustmentDocument::query()->count());
            $this->assertSame(1, $book->journals()->count());
        }
    }

    private function prepareLedger(TaxInvoice $invoice, User $actor): array
    {
        $book = AccountingBook::query()->where('code', 'TPZ-UAE')->firstOrFail();
        config()->set('accounting.posting_activation_approved', true);
        $ledger = app(AccountingLedgerService::class);
        $ledger->enablePosting($book, $actor);
        $ar = $book->accounts()->where('number', '1200')->firstOrFail();
        $sales = $book->accounts()->where('number', '4000')->firstOrFail();
        $vat = $book->accounts()->where('number', '2200')->firstOrFail();

        $original = $ledger->createDraft([
            'book_id' => $book->id,
            'posting_date' => now('Asia/Dubai')->toDateString(),
            'narration' => 'Original Tax Invoice '.$invoice->invoice_number,
            'source_type' => 'tax_invoice',
            'source_key' => (string) $invoice->id,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [
                ['account_id' => $ar->id, 'debit' => $invoice->grand_total],
                ['account_id' => $sales->id, 'credit' => $invoice->subtotal_excluding_vat],
                ['account_id' => $vat->id, 'credit' => $invoice->vat_amount],
            ],
        ], $actor);
        $posted = $ledger->post($original, $actor);

        return [$book, [
            'accounting_book_id' => $book->id,
            'original_accounting_journal_id' => $posted->id,
            'receivable_account_id' => $ar->id,
            'revenue_account_id' => $sales->id,
            'output_vat_account_id' => $vat->id,
            'financial_reconciliation_reference' => 'Unpaid original invoice validated by accountant.',
            'accountant_confirmation' => true,
        ], $posted];
    }

    private function approved(TaxInvoice $invoice, User $actor, string $price): \App\Models\TaxInvoicePriceAdjustmentProposal
    {
        $proposal = app(TaxInvoicePriceCorrectionService::class)->propose($invoice, [
            'reason' => 'Original unit price correction verified by finance.',
            'items' => [['id' => $invoice->items()->first()->id, 'unit_price_including_vat' => $price]],
        ], $actor);
        app(TaxInvoicePriceCorrectionService::class)->review($proposal, 'approved', 'Approved for controlled issue.', $actor);

        return $proposal;
    }

    private function invoice(User $actor): TaxInvoice
    {
        return app(TaxInvoiceService::class)->create([
            'customer_name' => 'Customer',
            'customer_address' => 'Dubai, UAE',
            'order_reference' => 'INVOICE-ISSUE-'.Str::random(8),
            'invoice_date' => now('Asia/Dubai')->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['description' => 'Laptop', 'quantity' => 2, 'unit_price_including_vat' => '525.00']],
        ], $actor);
    }

    private function owner(): User
    {
        $actor = User::factory()->create();
        Employee::factory()->for($actor)->role(EmployeeRole::Owner)
            ->create(['email' => $actor->email, 'status' => true]);
        app(CompanyProfileService::class)->save([
            'company_name_en' => 'Tech Point Zone',
            'company_name_ar' => 'تك بوينت زون',
            'trn' => '100000000000001', 'trade_license_number' => 'TL-1',
            'ded_registration_number' => 'DED-1', 'address_en' => 'Dubai, UAE',
            'address_ar' => 'دبي، الإمارات', 'phone' => '+9714000000',
            'mobile' => '+9715000000', 'email' => 'accounts@example.test',
            'legal_statement_en' => 'Registered company statement.',
            'legal_statement_ar' => 'بيان الشركة المسجلة.',
        ], $actor);

        return $actor;
    }
}
