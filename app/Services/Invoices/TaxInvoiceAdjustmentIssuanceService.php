<?php

namespace App\Services\Invoices;

use App\Enums\AccountingAccountType;
use App\Enums\AccountingJournalStatus;
use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountingPermission;
use App\Enums\InvoicePermission;
use App\Models\AccountingAccount;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceAdjustmentDocument;
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\User;
use App\Services\Accounting\AccountingLedgerService;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxInvoiceAdjustmentIssuanceService
{
    public function __construct(
        private readonly InvoiceAuthorization $invoices,
        private readonly AccountingAuthorization $accounting,
        private readonly AccountingLedgerService $ledger,
        private readonly ReferenceSequenceService $sequences,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Issue official numbered documents AND post linked accounting journals in
     * one database transaction. Inactive by default, even on Staging.
     * Never modifies the issued source Tax Invoice or historical journal.
     *
     * @param  array<string,mixed>  $input
     * @return \Illuminate\Support\Collection<int,TaxInvoiceAdjustmentDocument>
     */
    public function issue(TaxInvoicePriceAdjustmentProposal $proposal, array $input, User $actor): \Illuminate\Support\Collection
    {
        $this->invoices->authorize($actor, InvoicePermission::IssuePriceAdjustments);
        $this->accounting->authorize($actor, AccountingPermission::PostJournal);

        if (config('tax_invoice_adjustments.issuance_enabled', false) !== true) {
            throw ValidationException::withMessages(['issuance' => 'Tax adjustment document issuance is disabled pending Owner and UAE VAT/Accounting activation.']);
        }
        if (config('tax_invoice_adjustments.electronic_delivery_ready', false) !== true) {
            throw ValidationException::withMessages(['issuance' => 'E-invoice applicability/delivery compliance is not configured and approved.']);
        }

        $data = validator($input, [
            'accounting_book_id' => ['required', 'integer', 'exists:accounting_books,id'],
            'original_accounting_journal_id' => ['required', 'integer', 'exists:accounting_journals,id'],
            'receivable_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'revenue_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'output_vat_account_id' => ['required', 'integer', 'exists:accounting_accounts,id'],
            'financial_reconciliation_reference' => ['required', 'string', 'min:10', 'max:190'],
            'accountant_confirmation' => ['required', 'accepted'],
        ])->validate();

        // References must be allocated outside the business transaction by
        // ReferenceSequenceService. Unused reserved numbers are never reused.
        $year = (int) now('Asia/Dubai')->format('Y');
        $planned = collect($proposal->adjustment_documents_preview)
            ->filter(fn (array $document): bool => count($document['lines'] ?? []) > 0)
            ->keys();
        if ($planned->isEmpty()) {
            throw ValidationException::withMessages(['issuance' => 'The approved proposal contains no adjustment lines.']);
        }
        $references = [];
        foreach ($planned as $kind) {
            $key = $kind === 'tax_credit_note' ? 'tax_credit_note' : 'additional_tax_invoice';
            $prefix = $kind === 'tax_credit_note' ? 'TPZ-TCN' : 'TPZ-ADI';
            $references[$kind] = $prefix.'-'.$year.'-'.str_pad((string) $this->sequences->next("{$key}:{$year}"), 6, '0', STR_PAD_LEFT);
        }

        return DB::transaction(function () use ($proposal, $data, $actor, $references): \Illuminate\Support\Collection {
            $invoice = TaxInvoice::query()->lockForUpdate()->findOrFail($proposal->tax_invoice_id);
            $request = TaxInvoicePriceAdjustmentProposal::query()->with('decision')->lockForUpdate()->findOrFail($proposal->id);
            if ($invoice->status !== 'issued' || $request->decision?->decision !== 'approved') {
                throw ValidationException::withMessages(['issuance' => 'This request is not approved against a currently issued Invoice.']);
            }
            $existing = TaxInvoiceAdjustmentDocument::query()->where('proposal_id', $request->id)->get();
            if ($existing->isNotEmpty()) {
                throw ValidationException::withMessages(['issuance' => 'This adjustment has already been issued; no duplicate documents are permitted.']);
            }
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($data['accounting_book_id']);
            if ($book->active !== true || $book->posting_enabled !== true || strtoupper((string) $book->currency) !== 'AED') {
                throw ValidationException::withMessages(['accounting_book_id' => 'An active AED accounting book with explicitly enabled posting is required.']);
            }
            $originalJournal = AccountingJournal::query()->with('lines')->lockForUpdate()->findOrFail($data['original_accounting_journal_id']);
            if ($originalJournal->accounting_book_id !== $book->id
                || $originalJournal->status !== AccountingJournalStatus::Posted
                || $originalJournal->source_type !== 'tax_invoice'
                || $originalJournal->source_key !== (string) $invoice->id) {
                throw ValidationException::withMessages(['original_accounting_journal_id' => 'The original invoice must have a uniquely linked, posted tax_invoice accounting journal in this book.']);
            }
            if (AccountingJournal::query()
                ->where('accounting_book_id', $book->id)->where('source_type', 'tax_invoice')
                ->where('source_key', (string) $invoice->id)
                ->where('status', AccountingJournalStatus::Posted)->count() !== 1) {
                throw ValidationException::withMessages(['original_accounting_journal_id' => 'Original invoice accounting link is ambiguous. Reconcile before issuing adjustments.']);
            }
            $originalPeriod = AccountingPeriod::query()->lockForUpdate()->findOrFail($originalJournal->accounting_period_id);
            if ($originalPeriod->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['accounting_period' => 'The original invoice journal is in a closed/locked period. Use a separately advised VAT disclosure workflow; automatic issuance is blocked.']);
            }
            $date = now('Asia/Dubai')->toDateString();
            $postingPeriod = AccountingPeriod::query()
                ->where('accounting_book_id', $book->id)
                ->whereDate('starts_on', '<=', $date)
                ->whereDate('ends_on', '>=', $date)->lockForUpdate()->first();
            if ($postingPeriod?->status !== AccountingPeriodStatus::Open) {
                throw ValidationException::withMessages(['posting_date' => 'An open accounting period covering the UAE issue date is required.']);
            }
            $accounts = AccountingAccount::query()
                ->where('accounting_book_id', $book->id)
                ->whereIn('id', [
                    $data['receivable_account_id'], $data['revenue_account_id'], $data['output_vat_account_id'],
                ])->get()->keyBy('id');
            $mapping = [
                'receivable' => [$data['receivable_account_id'], AccountingAccountType::Asset],
                'revenue' => [$data['revenue_account_id'], AccountingAccountType::Income],
                'output_vat' => [$data['output_vat_account_id'], AccountingAccountType::Liability],
            ];
            foreach ($mapping as $name => [$id, $type]) {
                $account = $accounts[(int) $id] ?? null;
                if ($account === null || $account->account_type !== $type || $account->active !== true
                    || $account->is_posting !== true || $account->is_control === true) {
                    throw ValidationException::withMessages(['accounts' => "Invalid {$name} account. Only active, non-control posting accounts of the correct type are permitted."]);
                }
            }
            if (count(array_unique(array_map('intval', array_column($mapping, 0)))) !== 3) {
                throw ValidationException::withMessages(['accounts' => 'Receivables, revenue, and VAT control must use three distinct accounts.']);
            }
            $required = [
                'receivable' => ['debit', (string) $invoice->grand_total],
                'revenue' => ['credit', (string) $invoice->subtotal_excluding_vat],
                'output_vat' => ['credit', (string) $invoice->vat_amount],
            ];
            foreach ($required as $name => [$side, $amount]) {
                $sum = '0.00';
                foreach ($originalJournal->lines->where('account_id', (int) $mapping[$name][0]) as $line) {
                    $sum = bcadd($sum, (string) $line->{$side}, 2);
                }
                if (bccomp($sum, $amount, 2) !== 0) {
                    throw ValidationException::withMessages(['original_accounting_journal_id' => "Original posted journal {$name} balance must equal the original issued invoice amount."]);
                }
            }

            $documents = collect();
            foreach ($references as $kind => $number) {
                $preview = $request->adjustment_documents_preview[$kind] ?? null;
                if ($preview === null || count($preview['lines'] ?? []) === 0) {
                    throw ValidationException::withMessages(['issuance' => 'Adjustment line snapshot has changed.']);
                }
                if (bccomp(bcadd($preview['net'], $preview['vat'], 2), $preview['gross'], 2) !== 0) {
                    throw ValidationException::withMessages(['issuance' => 'Net plus VAT does not equal gross. Finance must resolve the rounding difference.']);
                }
                $credit = $kind === 'tax_credit_note';
                $journalLines = $credit
                    ? [
                        ['account_id' => $data['revenue_account_id'], 'debit' => $preview['net'], 'credit' => '0.00'],
                        ['account_id' => $data['output_vat_account_id'], 'debit' => $preview['vat'], 'credit' => '0.00'],
                        ['account_id' => $data['receivable_account_id'], 'debit' => '0.00', 'credit' => $preview['gross']],
                    ]
                    : [
                        ['account_id' => $data['receivable_account_id'], 'debit' => $preview['gross'], 'credit' => '0.00'],
                        ['account_id' => $data['revenue_account_id'], 'debit' => '0.00', 'credit' => $preview['net']],
                        ['account_id' => $data['output_vat_account_id'], 'debit' => '0.00', 'credit' => $preview['vat']],
                    ];
                $journal = $this->ledger->createDraft([
                    'book_id' => $book->id,
                    'posting_date' => $date,
                    'narration' => ($credit ? 'Tax Credit Note ' : 'Additional Tax Invoice ').$number.' for '.$invoice->invoice_number,
                    'idempotency_key' => (string) Str::uuid(),
                    'source_type' => 'tax_invoice_adjustment',
                    'source_key' => (string) $request->id.':'.$kind,
                    'lines' => $journalLines,
                ], $actor);
                $posted = $this->ledger->post($journal, $actor);
                if ($posted->status !== AccountingJournalStatus::Posted) {
                    throw ValidationException::withMessages(['journal' => 'Financial posting failed; no tax document was issued.']);
                }
                $doc = TaxInvoiceAdjustmentDocument::query()->create([
                    'proposal_id' => $request->id, 'original_invoice_id' => $invoice->id,
                    'accounting_book_id' => $book->id,
                    'original_accounting_journal_id' => $originalJournal->id,
                    'adjustment_accounting_journal_id' => $posted->id,
                    'issued_by_user_id' => $actor->id,
                    'document_number' => $number, 'kind' => $kind,
                    'issued_on' => $date, 'status' => 'issued',
                    'net_amount' => $preview['net'], 'vat_amount' => $preview['vat'], 'gross_amount' => $preview['gross'],
                    'original_invoice_net' => $invoice->subtotal_excluding_vat,
                    'corrected_invoice_net' => $request->proposed_line_snapshot
                        ? array_reduce($request->proposed_line_snapshot, fn (string $sum, array $line): string => bcadd($sum, (string) $line['net'], 2), '0.00')
                        : $invoice->subtotal_excluding_vat,
                    'seller_snapshot' => $invoice->seller_snapshot,
                    'customer_snapshot' => [
                        'name' => $invoice->customer_name, 'address' => $invoice->customer_address,
                        'trn' => $invoice->customer_trn, 'phone' => $invoice->customer_phone,
                    ],
                    'lines_snapshot' => $preview['lines'],
                    'explanation' => $request->reason,
                    'financial_reconciliation_reference' => trim($data['financial_reconciliation_reference']),
                    'issued_at' => now('UTC'),
                ]);
                $this->activity->log('tax_invoice.adjustment_document_issued', $actor, $invoice, [
                    'document_id' => $doc->id, 'document_number' => $number,
                    'source_invoice_id' => $invoice->id, 'journal_id' => $posted->id,
                    'kind' => $kind, 'gross' => $preview['gross'], 'vat' => $preview['vat'],
                ]);
                $documents->push($doc);
            }

            return $documents;
        }, 3);
    }
}
