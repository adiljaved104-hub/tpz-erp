<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingSupplierBill;
use App\Models\AccountingSupplierPayment;
use App\Models\AccountingSupplierPaymentAllocation;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountsPayableService
{
    public function __construct(
        private readonly AccountingAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function createDraftBill(array $data, User $actor): AccountingSupplierBill
    {
        $this->authorization->authorize($actor, AccountingPermission::CreateSupplierBill);
        $validated = Validator::make($data, [
            'accounting_book_id' => ['required', 'integer', 'exists:accounting_books,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'purchase_id' => ['nullable', 'integer', 'exists:purchases,id'],
            'bill_number' => ['nullable', 'string', 'max:80'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'currency' => ['required', 'size:3'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'source_type' => ['nullable', 'string', 'max:120'],
            'source_key' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:250'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'lines.*.vat_rate' => ['nullable', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.purchase_item_id' => ['nullable', 'integer', 'exists:purchase_items,id'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): AccountingSupplierBill {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($validated['accounting_book_id']);
            $existing = AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('idempotency_key', $validated['idempotency_key'])->with('lines')->first();
            if ($existing) {
                return $existing;
            }

            $subtotal = '0.00';
            $vat = '0.00';
            $lines = [];
            foreach ($validated['lines'] as $index => $line) {
                $quantity = number_format((float) $line['quantity'], 3, '.', '');
                $unitCost = number_format((float) $line['unit_cost'], 2, '.', '');
                $net = bcmul($quantity, $unitCost, 2);
                $rate = number_format((float) ($line['vat_rate'] ?? 0), 4, '.', '');
                $vatAmount = bcdiv(bcmul($net, $rate, 2), '100', 2);
                $total = bcadd($net, $vatAmount, 2);
                $subtotal = bcadd($subtotal, $net, 2);
                $vat = bcadd($vat, $vatAmount, 2);
                $lines[] = [
                    'description' => $line['description'],
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'net_amount' => $net,
                    'vat_rate' => $rate,
                    'vat_amount' => $vatAmount,
                    'total_amount' => $total,
                    'line_number' => $index + 1,
                    'product_id' => $line['product_id'] ?? null,
                    'purchase_item_id' => $line['purchase_item_id'] ?? null,
                ];
            }
            $bill = AccountingSupplierBill::query()->create([
                'accounting_book_id' => $book->id,
                'supplier_id' => $validated['supplier_id'],
                'purchase_id' => $validated['purchase_id'] ?? null,
                'bill_number' => $validated['bill_number'] ?? null,
                'bill_date' => $validated['bill_date'],
                'due_date' => $validated['due_date'] ?? null,
                'currency' => strtoupper($validated['currency']),
                'subtotal' => $subtotal,
                'vat_total' => $vat,
                'grand_total' => bcadd($subtotal, $vat, 2),
                'status' => 'draft',
                'idempotency_key' => $validated['idempotency_key'],
                'source_type' => $validated['source_type'] ?? null,
                'source_key' => $validated['source_key'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);
            $bill->lines()->createMany($lines);
            $this->activity->log('accounting.supplier_bill_draft_created', $actor, $bill, ['reference' => $bill->bill_number]);

            return $bill->load('lines');
        });
    }

    public function recordDraftPayment(array $data, User $actor): AccountingSupplierPayment
    {
        $this->authorization->authorize($actor, AccountingPermission::RecordSupplierPayment);
        $validated = Validator::make($data, [
            'accounting_book_id' => ['required', 'integer', 'exists:accounting_books,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'size:3'],
            'amount' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/', 'not_regex:/^0+(?:\.0{1,2})?$/'],
            'reference' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): AccountingSupplierPayment {
            $payment = AccountingSupplierPayment::query()->firstOrCreate(
                ['accounting_book_id' => $validated['accounting_book_id'], 'idempotency_key' => $validated['idempotency_key']],
                [...$validated, 'currency' => strtoupper($validated['currency']), 'status' => 'draft', 'created_by_user_id' => $actor->id],
            );
            $this->activity->log('accounting.supplier_payment_draft_created', $actor, $payment, ['reference' => $payment->reference]);

            return $payment;
        });
    }

    public function allocatePayment(AccountingSupplierPayment $payment, array $allocations, User $actor): AccountingSupplierPayment
    {
        $this->authorization->authorize($actor, AccountingPermission::AllocateSupplierPayment);

        return DB::transaction(function () use ($payment, $allocations): AccountingSupplierPayment {
            $payment = AccountingSupplierPayment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            foreach ($allocations as $allocation) {
                $bill = AccountingSupplierBill::query()->lockForUpdate()->findOrFail($allocation['bill_id']);
                if ($bill->accounting_book_id !== $payment->accounting_book_id || $bill->supplier_id !== $payment->supplier_id) {
                    throw ValidationException::withMessages(['allocations' => 'Payment and bill must belong to the same book and supplier.']);
                }
                $amount = number_format((float) $allocation['amount'], 2, '.', '');
                $already = (string) $bill->allocations()->sum('amount');
                if (bccomp(bcadd($already, $amount, 2), (string) $bill->grand_total, 2) > 0) {
                    throw ValidationException::withMessages(['allocations' => 'Allocation exceeds the supplier bill balance.']);
                }
                AccountingSupplierPaymentAllocation::query()->firstOrCreate(['payment_id' => $payment->id, 'bill_id' => $bill->id], ['amount' => $amount]);
            }
            $allocated = (string) $payment->allocations()->sum('amount');
            if (bccomp($allocated, (string) $payment->amount, 2) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Allocations exceed the supplier payment amount.']);
            }

            return $payment->load('allocations');
        });
    }

    public function supplierBalance(AccountingBook $book, int $supplierId): string
    {
        $billed = (string) AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->whereNotIn('status', ['voided'])->sum('grand_total');
        $paid = (string) DB::table('accounting_supplier_payment_allocations as allocation')
            ->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->where('payment.accounting_book_id', $book->id)
            ->where('payment.supplier_id', $supplierId)
            ->sum('allocation.amount');

        return bcsub($billed, $paid, 2);
    }
}
