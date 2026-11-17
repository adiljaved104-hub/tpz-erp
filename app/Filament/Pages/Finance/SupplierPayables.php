<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingSupplierAdjustment;
use App\Models\AccountingSupplierBill;
use App\Models\AccountingSupplierPayment;
use App\Models\Supplier;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierPayables extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.supplier-payables';

    protected static ?string $slug = 'finance/accounting/supplier-payables';

    protected static ?string $navigationLabel = 'Supplier Payables';

    protected static ?int $navigationSort = 35;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewPayables;

    public string $bookId = '';

    public string $supplierId = '';

    public string $billNumber = '';

    public string $billDate = '';

    public string $dueDate = '';

    public array $billLines = [
        ['description' => '', 'quantity' => '1', 'unit_cost' => '', 'vat_rate' => '0'],
    ];

    public string $paymentDate = '';

    public string $paymentAmount = '';

    public string $paymentReference = '';

    public string $adjustmentKind = 'credit';

    public string $adjustmentDate = '';

    public string $adjustmentAmount = '';

    public string $adjustmentReference = '';

    public string $adjustmentReason = '';

    public string $allocationPaymentId = '';

    public string $allocationBillId = '';

    public string $allocationAmount = '';

    public string $allocationPaymentSearch = '';

    public string $allocationBillSearch = '';

    public string $asOfDate = '';

    public function mount(): void
    {
        parent::mount();
        $this->bookId = (string) (AccountingBook::query()->value('id') ?? '');
        $today = now()->toDateString();
        $this->billDate = $today;
        $this->paymentDate = $today;
        $this->adjustmentDate = $today;
        $this->asOfDate = $today;
    }

    public function saveBill(): void
    {
        try {
            app(AccountsPayableService::class)->createDraftBill([
                'accounting_book_id' => (int) $this->bookId,
                'supplier_id' => (int) $this->supplierId,
                'bill_number' => $this->billNumber ?: null,
                'bill_date' => $this->billDate,
                'due_date' => $this->dueDate ?: null,
                'currency' => $this->book()->currency ?? 'AED',
                'idempotency_key' => (string) Str::uuid(),
                'lines' => array_values($this->billLines),
            ], $this->user());
            $this->reset('billNumber', 'dueDate');
            $this->billLines = [self::blankBillLine()];
            Notification::make()->success()->title('Supplier bill saved as draft')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
    }

    public function addBillLine(): void
    {
        $this->billLines[] = self::blankBillLine();
    }

    public function removeBillLine(int $index): void
    {
        if (count($this->billLines) <= 1 || ! array_key_exists($index, $this->billLines)) {
            return;
        }

        unset($this->billLines[$index]);
        $this->billLines = array_values($this->billLines);
    }

    public function savePayment(): void
    {
        try {
            app(AccountsPayableService::class)->recordDraftPayment([
                'accounting_book_id' => (int) $this->bookId, 'supplier_id' => (int) $this->supplierId, 'payment_date' => $this->paymentDate,
                'currency' => $this->book()->currency ?? 'AED', 'amount' => $this->paymentAmount, 'reference' => $this->paymentReference, 'idempotency_key' => (string) Str::uuid(),
            ], $this->user());
            $this->reset('paymentAmount', 'paymentReference');
            Notification::make()->success()->title('Supplier payment saved as draft')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
    }

    public function saveAdjustment(): void
    {
        try {
            app(AccountsPayableService::class)->recordDraftAdjustment([
                'accounting_book_id' => (int) $this->bookId, 'supplier_id' => (int) $this->supplierId, 'kind' => $this->adjustmentKind, 'adjustment_date' => $this->adjustmentDate,
                'currency' => $this->book()->currency ?? 'AED', 'amount' => $this->adjustmentAmount, 'reference' => $this->adjustmentReference, 'idempotency_key' => (string) Str::uuid(), 'reason' => $this->adjustmentReason,
            ], $this->user());
            $this->reset('adjustmentAmount', 'adjustmentReference', 'adjustmentReason');
            Notification::make()->success()->title('Supplier adjustment saved as draft')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
    }

    public function allocatePayment(): void
    {
        try {
            app(AccountsPayableService::class)->allocatePayment(AccountingSupplierPayment::query()->findOrFail((int) $this->allocationPaymentId), [['bill_id' => (int) $this->allocationBillId, 'amount' => $this->allocationAmount]], $this->user());
            $this->reset('allocationPaymentId', 'allocationBillId', 'allocationAmount', 'allocationPaymentSearch', 'allocationBillSearch');
            Notification::make()->success()->title('Payment allocation saved')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
    }

    public function updatedAllocationPaymentSearch(): void
    {
        $this->allocationPaymentId = '';
        $this->allocationBillId = '';
        $this->allocationBillSearch = '';
        $this->allocationAmount = '';
    }

    public function updatedAllocationBillSearch(): void
    {
        $this->allocationBillId = '';
        $this->allocationAmount = '';
    }

    public function selectAllocationPayment(int $paymentId): void
    {
        $payment = AccountingSupplierPayment::query()
            ->with('supplier')
            ->where('accounting_book_id', (int) $this->bookId)
            ->where('supplier_id', (int) $this->supplierId)
            ->where('status', '!=', 'voided')
            ->findOrFail($paymentId);

        $this->allocationPaymentId = (string) $payment->id;
        $this->allocationPaymentSearch = trim(implode(' · ', array_filter([
            $payment->reference,
            $payment->supplier?->name,
            $payment->currency.' '.number_format((float) $payment->amount, 2),
        ])));
        $this->allocationBillId = '';
        $this->allocationBillSearch = '';
        $this->allocationAmount = '';
    }

    public function selectAllocationBill(int $billId): void
    {
        $payment = AccountingSupplierPayment::query()
            ->where('id', (int) $this->allocationPaymentId)
            ->where('accounting_book_id', (int) $this->bookId)
            ->where('supplier_id', (int) $this->supplierId)
            ->where('status', '!=', 'voided')
            ->firstOrFail();

        $bill = AccountingSupplierBill::query()
            ->where('id', $billId)
            ->where('accounting_book_id', $payment->accounting_book_id)
            ->where('supplier_id', $payment->supplier_id)
            ->where('currency', $payment->currency)
            ->where('status', '!=', 'voided')
            ->firstOrFail();

        $this->allocationBillId = (string) $bill->id;
        $this->allocationBillSearch = trim(implode(' · ', array_filter([
            $bill->bill_number ?: 'Bill #'.$bill->id,
            $bill->bill_date?->format('Y-m-d'),
            $bill->currency.' '.number_format((float) $bill->grand_total, 2),
        ])));
        $this->allocationAmount = '';
    }

    public function updatedBookId(): void
    {
        $this->resetAllocationSelection();
    }

    public function updatedSupplierId(): void
    {
        $this->resetAllocationSelection();
    }

    public function approveBill(int $id): void
    {
        app(AccountsPayableService::class)->approveBill(AccountingSupplierBill::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Supplier bill approved')->send();
    }

    public function approvePayment(int $id): void
    {
        app(AccountsPayableService::class)->approvePayment(AccountingSupplierPayment::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Supplier payment approved')->send();
    }

    public function approveAdjustment(int $id): void
    {
        app(AccountsPayableService::class)->approveAdjustment(AccountingSupplierAdjustment::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Supplier adjustment approved')->send();
    }

    protected function getViewData(): array
    {
        $books = AccountingBook::query()->orderBy('name')->get();
        $book = $this->book();
        if ($book && $this->bookId === '') {
            $this->bookId = (string) $book->id;
        }

        $supplierId = (int) $this->supplierId;
        $payments = $book ? AccountingSupplierPayment::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect();
        $bills = $book ? AccountingSupplierBill::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect();
        $adjustments = $book ? AccountingSupplierAdjustment::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect();
        $selectedPayment = $book && $this->allocationPaymentId !== '' && $supplierId > 0
            ? AccountingSupplierPayment::query()
                ->with('supplier:id,name')
                ->withSum('allocations as allocated_total', 'amount')
                ->where('accounting_book_id', $book->id)
                ->where('supplier_id', $supplierId)
                ->where('status', '!=', 'voided')
                ->find((int) $this->allocationPaymentId)
            : null;
        if ($selectedPayment) {
            $selectedPayment->available_amount = bcsub((string) $selectedPayment->amount, (string) ($selectedPayment->allocated_total ?? '0.00'), 2);
        }
        $allocationPayments = $book && $supplierId > 0
            ? AccountingSupplierPayment::query()
                ->with('supplier:id,name')
                ->withSum('allocations as allocated_total', 'amount')
                ->where('accounting_book_id', $book->id)
                ->where('supplier_id', $supplierId)
                ->where('status', '!=', 'voided')
                ->latest('payment_date')->latest('id')->limit(100)->get()
                ->map(function (AccountingSupplierPayment $payment): AccountingSupplierPayment {
                    $payment->available_amount = bcsub((string) $payment->amount, (string) ($payment->allocated_total ?? '0.00'), 2);

                    return $payment;
                })
                ->filter(fn (AccountingSupplierPayment $payment): bool => bccomp($payment->available_amount, '0.00', 2) > 0)
                ->filter(fn (AccountingSupplierPayment $payment): bool => $this->matchesSearch($this->allocationPaymentSearch, [$payment->reference, $payment->supplier?->name, $payment->currency, $payment->status]))
                ->values()
            : collect();
        $allocationBills = $selectedPayment
            ? AccountingSupplierBill::query()
                ->withSum('allocations as allocated_total', 'amount')
                ->withSum(['allocations as approved_paid_total' => fn ($query) => $query->whereHas('payment', fn ($payment) => $payment->whereIn('status', ['approved', 'posted'])->whereDate('payment_date', '<=', now()->toDateString()))], 'amount')
                ->withSum(['creditAllocations as approved_credit_total' => fn ($query) => $query->whereHas('adjustment', fn ($adjustment) => $adjustment->where('kind', 'credit')->whereIn('status', ['approved', 'posted']))], 'amount')
                ->withSum(['creditAllocations as approved_debit_total' => fn ($query) => $query->whereHas('adjustment', fn ($adjustment) => $adjustment->where('kind', 'debit')->whereIn('status', ['approved', 'posted']))], 'amount')
                ->where('accounting_book_id', $selectedPayment->accounting_book_id)
                ->where('supplier_id', $selectedPayment->supplier_id)
                ->where('currency', $selectedPayment->currency)
                ->where('status', '!=', 'voided')
                ->latest('bill_date')->latest('id')->limit(100)->get()
                ->map(function (AccountingSupplierBill $bill): AccountingSupplierBill {
                    $bill->allocatable_amount = bcsub((string) $bill->grand_total, (string) ($bill->allocated_total ?? '0.00'), 2);
                    $bill->outstanding_amount = bcadd(
                        bcsub(bcsub((string) $bill->grand_total, (string) ($bill->approved_paid_total ?? '0.00'), 2), (string) ($bill->approved_credit_total ?? '0.00'), 2),
                        (string) ($bill->approved_debit_total ?? '0.00'),
                        2,
                    );

                    return $bill;
                })
                ->filter(fn (AccountingSupplierBill $bill): bool => bccomp($bill->allocatable_amount, '0.00', 2) > 0)
                ->filter(fn (AccountingSupplierBill $bill): bool => $this->matchesSearch($this->allocationBillSearch, [$bill->bill_number, (string) $bill->id, $bill->bill_date?->format('Y-m-d'), $bill->currency, $bill->status]))
                ->values()
            : collect();
        $selectedBill = $selectedPayment && $this->allocationBillId !== ''
            ? AccountingSupplierBill::query()
                ->withSum('allocations as allocated_total', 'amount')
                ->withSum(['allocations as approved_paid_total' => fn ($query) => $query->whereHas('payment', fn ($payment) => $payment->whereIn('status', ['approved', 'posted'])->whereDate('payment_date', '<=', now()->toDateString()))], 'amount')
                ->withSum(['creditAllocations as approved_credit_total' => fn ($query) => $query->whereHas('adjustment', fn ($adjustment) => $adjustment->where('kind', 'credit')->whereIn('status', ['approved', 'posted']))], 'amount')
                ->withSum(['creditAllocations as approved_debit_total' => fn ($query) => $query->whereHas('adjustment', fn ($adjustment) => $adjustment->where('kind', 'debit')->whereIn('status', ['approved', 'posted']))], 'amount')
                ->where('accounting_book_id', $selectedPayment->accounting_book_id)
                ->where('supplier_id', $selectedPayment->supplier_id)
                ->where('currency', $selectedPayment->currency)
                ->where('status', '!=', 'voided')
                ->find((int) $this->allocationBillId)
            : null;
        if ($selectedBill) {
            $selectedBill->outstanding_amount = bcadd(
                bcsub(bcsub((string) $selectedBill->grand_total, (string) ($selectedBill->approved_paid_total ?? '0.00'), 2), (string) ($selectedBill->approved_credit_total ?? '0.00'), 2),
                (string) ($selectedBill->approved_debit_total ?? '0.00'),
                2,
            );
        }

        return [
            'books' => $books,
            'book' => $book,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'bills' => $bills,
            'payments' => $payments,
            'adjustments' => $adjustments,
            'allocationPayments' => $allocationPayments,
            'allocationBills' => $allocationBills,
            'selectedPayment' => $selectedPayment,
            'selectedBill' => $selectedBill,
            'postingEnabled' => (bool) ($book?->posting_enabled ?? false),
            'draftBillTotals' => $this->draftBillTotals(),
            'ageing' => $book && $supplierId > 0 ? app(AccountsPayableService::class)->supplierAgeing($book, $supplierId, $this->asOfDate, $this->user()) : collect(),
            'supplierBalance' => $book && $supplierId > 0 ? app(AccountsPayableService::class)->supplierBalance($book, $supplierId, $this->user()) : null,
            'canCreateBill' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::CreateSupplierBill),
            'canRecordPayment' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::RecordSupplierPayment),
            'canManageCredits' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ManageSupplierCredits),
            'canAllocatePayment' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::AllocateSupplierPayment),
            'canApprove' => app(AccountingAuthorization::class)->allows($this->user(), AccountingPermission::ApproveSupplierPayables),
        ];
    }

    private function book(): ?AccountingBook
    {
        return AccountingBook::query()->find((int) $this->bookId) ?? AccountingBook::query()->first();
    }

    private function applyErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
    }

    private function resetAllocationSelection(): void
    {
        $this->reset('allocationPaymentId', 'allocationBillId', 'allocationAmount', 'allocationPaymentSearch', 'allocationBillSearch');
    }

    /** @param array<int, string|null> $values */
    private function matchesSearch(string $search, array $values): bool
    {
        if (trim($search) === '') {
            return true;
        }

        $query = mb_strtolower(trim($search));

        return collect($values)->contains(fn (?string $value): bool => $value !== null && str_contains(mb_strtolower($value), $query));
    }

    /** @return array{subtotal:string,vat:string,total:string}|null */
    private function draftBillTotals(): ?array
    {
        $subtotal = '0.00';
        $vat = '0.00';

        foreach ($this->billLines as $line) {
            $quantity = trim((string) ($line['quantity'] ?? ''));
            $unitCost = trim((string) ($line['unit_cost'] ?? ''));
            $vatRate = trim((string) ($line['vat_rate'] ?? ''));
            $unitCost = $unitCost === '' ? '0' : $unitCost;
            $vatRate = $vatRate === '' ? '0' : $vatRate;

            if (preg_match('/^\d+(?:\.\d{1,3})?$/', $quantity) !== 1
                || bccomp($quantity, '0', 3) <= 0
                || preg_match('/^\d+(?:\.\d{1,2})?$/', $unitCost) !== 1
                || preg_match('/^\d+(?:\.\d{1,4})?$/', $vatRate) !== 1) {
                return null;
            }

            $net = bcmul($quantity, $unitCost, 2);
            $lineVat = bcdiv(bcmul($net, $vatRate, 2), '100', 2);
            $subtotal = bcadd($subtotal, $net, 2);
            $vat = bcadd($vat, $lineVat, 2);
        }

        return [
            'subtotal' => $subtotal,
            'vat' => $vat,
            'total' => bcadd($subtotal, $vat, 2),
        ];
    }

    private static function blankBillLine(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit_cost' => '', 'vat_rate' => '0'];
    }
}
