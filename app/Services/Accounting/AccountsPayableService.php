<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingSupplierAdjustment;
use App\Models\AccountingSupplierBill;
use App\Models\AccountingSupplierPayment;
use App\Models\AccountingSupplierPaymentAllocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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
            'lines.*.quantity' => ['required', 'regex:/^\\d+(?:\\.\\d{1,3})?$/', 'not_regex:/^0+(?:\\.0{1,3})?$/'],
            'lines.*.unit_cost' => ['required', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'lines.*.vat_rate' => ['nullable', 'regex:/^\d+(?:\.\d{1,4})?$/'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.purchase_item_id' => ['nullable', 'integer', 'exists:purchase_items,id'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): AccountingSupplierBill {
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($validated['accounting_book_id']);
            $existing = AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('idempotency_key', $validated['idempotency_key'])->with('lines')->first();
            if ($existing) {
                $this->assertSameBillPayload($existing, $validated);

                return $existing;
            }

            $subtotal = '0.00';
            $vat = '0.00';
            $lines = [];
            foreach ($validated['lines'] as $index => $line) {
                $quantity = self::decimal($line['quantity'], 3);
                $unitCost = self::decimal($line['unit_cost'], 2);
                $net = bcmul($quantity, $unitCost, 2);
                $rate = self::decimal($line['vat_rate'] ?? '0', 4);
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
            $book = AccountingBook::query()->lockForUpdate()->findOrFail($validated['accounting_book_id']);
            $this->assertCurrency($book, $validated['currency']);
            $existing = AccountingSupplierPayment::query()->where('accounting_book_id', $book->id)->where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                $matches = (int) $existing->supplier_id === (int) $validated['supplier_id'] && $existing->payment_date?->format('Y-m-d') === $validated['payment_date'] && strtoupper((string) $existing->currency) === strtoupper($validated['currency']) && bccomp((string) $existing->amount, self::decimal($validated['amount'], 2), 2) === 0 && (string) $existing->reference === (string) $validated['reference'];
                throw_unless($matches, ValidationException::withMessages(['idempotency_key' => 'Conflicting payment replay for this idempotency key.']));
                return $existing;
            }
            $payment = AccountingSupplierPayment::query()->create([
                'accounting_book_id' => $book->id, 'supplier_id' => $validated['supplier_id'], 'payment_date' => $validated['payment_date'],
                'currency' => strtoupper($validated['currency']), 'amount' => self::decimal($validated['amount'], 2), 'reference' => trim($validated['reference']),
                'status' => 'draft', 'idempotency_key' => $validated['idempotency_key'], 'created_by_user_id' => $actor->id,
            ]);
            $this->activity->log('accounting.supplier_payment_draft_created', $actor, $payment, ['reference' => $payment->reference]);
            return $payment;
        });
    }

    public function allocatePayment(AccountingSupplierPayment $payment, array $allocations, User $actor): AccountingSupplierPayment
    {
        $this->authorization->authorize($actor, AccountingPermission::AllocateSupplierPayment);
        throw_if($allocations === [], ValidationException::withMessages(['allocations' => 'At least one allocation is required.']));
        return DB::transaction(function () use ($payment, $allocations, $actor): AccountingSupplierPayment {
            $payment = AccountingSupplierPayment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            throw_if($payment->status === 'voided', ValidationException::withMessages(['payment' => 'Voided payments cannot be allocated.']));
            $seen = [];
            foreach ($allocations as $allocation) {
                $billId = $allocation['bill_id'] ?? null; $input = $allocation['amount'] ?? null;
                throw_if(! is_numeric($billId) || ! is_string((string) $input) || ! preg_match('/^\\d+(?:\\.\\d{1,2})?$/', (string) $input), ValidationException::withMessages(['allocations' => 'Each allocation needs a positive decimal amount and bill.']));
                $amount = self::decimal((string) $input, 2); throw_if(bccomp($amount, '0.00', 2) <= 0, ValidationException::withMessages(['allocations' => 'Allocation amounts must be positive.']));
                $billId = (int) $billId; throw_if(isset($seen[$billId]), ValidationException::withMessages(['allocations' => 'Duplicate bill allocation rows are not allowed.'])); $seen[$billId] = true;
                $bill = AccountingSupplierBill::query()->lockForUpdate()->withSum('allocations', 'amount')->findOrFail($billId);
                throw_if($bill->status === 'voided', ValidationException::withMessages(['allocations' => 'Voided bills cannot be allocated.']));
                if ($bill->accounting_book_id !== $payment->accounting_book_id || $bill->supplier_id !== $payment->supplier_id || strtoupper((string) $bill->currency) !== strtoupper((string) $payment->currency)) throw ValidationException::withMessages(['allocations' => 'Payment and bill must belong to the same book, supplier, and currency.']);
                $existing = $payment->allocations()->where('bill_id', $billId)->first();
                if ($existing) { throw_unless(bccomp((string) $existing->amount, $amount, 2) === 0, ValidationException::withMessages(['allocations' => 'Conflicting allocation replay for this payment and bill.'])); continue; }
                $already = (string) ($bill->allocations_sum_amount ?? '0.00');
                throw_if(bccomp(bcadd($already, $amount, 2), (string) $bill->grand_total, 2) > 0, ValidationException::withMessages(['allocations' => 'Allocation exceeds the supplier bill balance.']));
                AccountingSupplierPaymentAllocation::query()->create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => $amount]);
            }
            $allocated = (string) $payment->allocations()->sum('amount');
            throw_if(bccomp($allocated, (string) $payment->amount, 2) > 0, ValidationException::withMessages(['allocations' => 'Allocations exceed the supplier payment amount.']));
            $this->activity->log('accounting.supplier_payment_allocated', $actor, $payment, ['allocation_total' => $allocated]);
            return $payment->load('allocations');
        });
    }
    public function approveBill(AccountingSupplierBill $bill, User $actor): AccountingSupplierBill
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);
        return DB::transaction(function () use ($bill, $actor): AccountingSupplierBill {
            $bill = AccountingSupplierBill::query()->lockForUpdate()->findOrFail($bill->id);
            throw_if($bill->status !== 'draft', ValidationException::withMessages(['bill' => 'Only draft bills can be approved.']));
            $bill->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()])->save();
            $this->activity->log('accounting.supplier_bill_approved', $actor, $bill); return $bill;
        });
    }

    public function approvePayment(AccountingSupplierPayment $payment, User $actor): AccountingSupplierPayment
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);
        return DB::transaction(function () use ($payment): AccountingSupplierPayment {
            $payment = AccountingSupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            throw_if($payment->status !== 'draft', ValidationException::withMessages(['payment' => 'Only draft payments can be approved.']));
            $payment->update(['status' => 'approved']); return $payment;
        });
    }

    public function approveAdjustment(AccountingSupplierAdjustment $adjustment, User $actor): AccountingSupplierAdjustment
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);
        return DB::transaction(function () use ($adjustment): AccountingSupplierAdjustment {
            $adjustment = AccountingSupplierAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            throw_if($adjustment->status !== 'draft', ValidationException::withMessages(['adjustment' => 'Only draft adjustments can be approved.']));
            $adjustment->update(['status' => 'approved']); return $adjustment;
        });
    }
    public function recordDraftAdjustment(array $data, User $actor): AccountingSupplierAdjustment
    {
        $this->authorization->authorize($actor, AccountingPermission::ManageSupplierCredits);
        $validated = Validator::make($data, [
            'accounting_book_id' => ['required', 'integer', 'exists:accounting_books,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'bill_id' => ['nullable', 'integer', 'exists:accounting_supplier_bills,id'],
            'kind' => ['required', 'in:credit,debit'],
            'adjustment_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'size:3'],
            'amount' => ['required', 'regex:/^\\d+(?:\\.\\d{1,2})?$/', 'not_regex:/^0+(?:\\.0{1,2})?$/'],
            'reference' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor): AccountingSupplierAdjustment {
            $existing = AccountingSupplierAdjustment::query()
                ->where('accounting_book_id', $validated['accounting_book_id'])
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $adjustment = AccountingSupplierAdjustment::query()->create([
                ...$validated,
                'currency' => strtoupper($validated['currency']),
                'status' => 'draft',
                'created_by_user_id' => $actor->id,
            ]);
            $this->activity->log('accounting.supplier_adjustment_draft_created', $actor, $adjustment, ['reference' => $adjustment->reference]);

            return $adjustment;
        });
    }

    public function supplierBalance(AccountingBook $book, int $supplierId, User $actor): string
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        return $this->sumBalance($book, $supplierId, ['approved', 'posted']);
    }

    public function draftExposure(AccountingBook $book, int $supplierId, User $actor): string
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        return $this->sumBalance($book, $supplierId, ['draft', 'approved', 'posted']);
    }

    public function supplierAgeing(AccountingBook $book, int $supplierId, string $asOfDate, User $actor): Collection
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $asOfDate)->startOfDay();
        return AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->whereIn('status', ['approved', 'posted'])->withSum('allocations', 'amount')->orderBy('due_date')->get()->map(function (AccountingSupplierBill $bill) use ($asOf): AccountingSupplierBill {
            $allocated = (string) ($bill->allocations_sum_amount ?? '0.00');
            $bill->setAttribute('outstanding_amount', bcsub((string) $bill->grand_total, $allocated, 2));
            $bill->setAttribute('days_overdue', $bill->due_date && $bill->due_date->lt($asOf) ? $bill->due_date->diffInDays($asOf) : 0); return $bill;
        })->filter(fn (AccountingSupplierBill $bill): bool => bccomp((string) $bill->outstanding_amount, '0.00', 2) > 0)->values();
    }

    private function sumBalance(AccountingBook $book, int $supplierId, array $statuses): string
    {
        $billed = (string) AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->whereIn('status', $statuses)->sum('grand_total');
        $credits = (string) AccountingSupplierAdjustment::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->where('kind', 'credit')->whereIn('status', $statuses)->sum('amount');
        $debits = (string) AccountingSupplierAdjustment::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->where('kind', 'debit')->whereIn('status', $statuses)->sum('amount');
        $paid = (string) DB::table('accounting_supplier_payment_allocations as allocation')->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')->where('payment.accounting_book_id', $book->id)->where('payment.supplier_id', $supplierId)->whereIn('payment.status', $statuses)->sum('allocation.amount');
        return bcsub(bcadd(bcsub($billed, $credits, 2), $debits, 2), $paid, 2);
    }

    private function assertCurrency(AccountingBook $book, string $currency): void
    {
        throw_if(strtoupper((string) $book->currency) !== strtoupper($currency), ValidationException::withMessages(['currency' => 'Currency must match the accounting book currency.']));
    }

    private function assertSameBillPayload(AccountingSupplierBill $bill, array $data): void
    {
        $matches = (int) $bill->supplier_id === (int) $data['supplier_id'] && (int) ($bill->purchase_id ?? 0) === (int) ($data['purchase_id'] ?? 0) && $bill->bill_date?->format('Y-m-d') === $data['bill_date'] && strtoupper((string) $bill->currency) === strtoupper($data['currency']) && count($bill->lines) === count($data['lines']);
        if ($matches) foreach ($bill->lines as $index => $line) { $input = $data['lines'][$index]; $matches = $matches && (string) $line->description === trim($input['description']) && (string) $line->quantity === self::decimal($input['quantity'], 3) && (string) $line->unit_cost === self::decimal($input['unit_cost'], 2) && (string) $line->vat_rate === self::decimal($input['vat_rate'] ?? '0', 4); }
        throw_unless($matches, ValidationException::withMessages(['idempotency_key' => 'Conflicting bill replay for this idempotency key.']));
    }

    private static function decimal(string|int|float $value, int $scale): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\\d+(?:\\.\\d{1,' . $scale . '})?$/', $value)) throw ValidationException::withMessages(['amount' => 'Invalid decimal value.']);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, ''); return $whole . '.' . str_pad($fraction, $scale, '0');
    }
}
