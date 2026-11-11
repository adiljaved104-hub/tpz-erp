<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingSupplierAdjustment;
use App\Models\AccountingSupplierBill;
use App\Models\AccountingSupplierPayment;
use App\Models\Supplier;
use App\Services\Accounting\AccountsPayableService;
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

    public string $billDescription = '';

    public string $billQuantity = '1';

    public string $billUnitCost = '';

    public string $billVatRate = '0';

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

    public function mount(): void
    {
        parent::mount();
        $this->bookId = (string) (AccountingBook::query()->value('id') ?? '');
        $today = now()->toDateString();
        $this->billDate = $today;
        $this->paymentDate = $today;
        $this->adjustmentDate = $today;
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
                'lines' => [['description' => $this->billDescription, 'quantity' => $this->billQuantity, 'unit_cost' => $this->billUnitCost, 'vat_rate' => $this->billVatRate]],
            ], $this->user());
            $this->reset('billNumber', 'dueDate', 'billDescription', 'billQuantity', 'billUnitCost', 'billVatRate');
            $this->billQuantity = '1';
            Notification::make()->success()->title('Supplier bill saved as draft')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
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
            $this->reset('allocationPaymentId', 'allocationBillId', 'allocationAmount');
            Notification::make()->success()->title('Payment allocation saved')->send();
        } catch (ValidationException $exception) {
            $this->applyErrors($exception);
        }
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

        return [
            'books' => $books, 'book' => $book, 'suppliers' => Supplier::query()->orderBy('name')->get(),
            'bills' => $book ? AccountingSupplierBill::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect(),
            'payments' => $book ? AccountingSupplierPayment::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect(),
            'adjustments' => $book ? AccountingSupplierAdjustment::query()->with('supplier')->where('accounting_book_id', $book->id)->latest()->limit(50)->get() : collect(),
            'postingEnabled' => (bool) ($book?->posting_enabled ?? false),
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
}
