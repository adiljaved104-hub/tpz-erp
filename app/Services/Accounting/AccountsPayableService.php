<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingSupplierAdjustment;
use App\Models\AccountingSupplierAdjustmentAllocation;
use App\Models\AccountingSupplierBill;
use App\Models\AccountingSupplierPayment;
use App\Models\AccountingSupplierPaymentAllocation;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountsPayableService
{
    private readonly AccountingAuthorization $authorization;

    private readonly ActivityLogger $activity;

    public function __construct(
        AccountingAuthorization $authorization,
        ActivityLogger $activity,
    ) {
        $this->authorization = $authorization;
        $this->activity = $activity;
    }

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
                $billId = $allocation['bill_id'] ?? null;
                $input = $allocation['amount'] ?? null;
                throw_if(is_numeric($billId) === false || is_string((string) $input) === false || preg_match('/^\\d+(?:\\.\\d{1,2})?$/', (string) $input) !== 1, ValidationException::withMessages(['allocations' => 'Each allocation needs a positive decimal amount and bill.']));
                $amount = self::decimal((string) $input, 2);
                throw_if(bccomp($amount, '0.00', 2) <= 0, ValidationException::withMessages(['allocations' => 'Allocation amounts must be positive.']));
                $billId = (int) $billId;
                throw_if(isset($seen[$billId]), ValidationException::withMessages(['allocations' => 'Duplicate bill allocation rows are not allowed.']));
                $seen[$billId] = true;
                $bill = AccountingSupplierBill::query()->lockForUpdate()->withSum('allocations', 'amount')->findOrFail($billId);
                throw_if($bill->status === 'voided', ValidationException::withMessages(['allocations' => 'Voided bills cannot be allocated.']));
                if ($bill->accounting_book_id !== $payment->accounting_book_id || $bill->supplier_id !== $payment->supplier_id || strtoupper((string) $bill->currency) !== strtoupper((string) $payment->currency)) {
                    throw ValidationException::withMessages(['allocations' => 'Payment and bill must belong to the same book, supplier, and currency.']);
                }
                $existing = $payment->allocations()->where('bill_id', $billId)->first();
                if ($existing) {
                    throw_unless(bccomp((string) $existing->amount, $amount, 2) === 0, ValidationException::withMessages(['allocations' => 'Conflicting allocation replay for this payment and bill.']));

                    continue;
                }
                $already = (string) ($bill->allocations_sum_amount ?? '0.00');
                throw_if(bccomp(bcadd($already, $amount, 2), (string) $bill->grand_total, 2) > 0, ValidationException::withMessages(['allocations' => 'Allocation exceeds the supplier bill balance.']));
                AccountingSupplierPaymentAllocation::query()->create(['payment_id' => $payment->id, 'bill_id' => $bill->id, 'amount' => $amount]);
            }
            $allocated = (string) $payment->allocations()->sum('amount');
            throw_if(bccomp($allocated, (string) $payment->amount, 2) > 0, ValidationException::withMessages(['allocations' => 'Allocations exceed the supplier payment amount.']));
            $this->activity->log('accounting.supplier_payment_allocated', $actor, $payment, ['allocation_recorded' => true]);

            return $payment->load('allocations');
        });
    }

    /** @param array<int, array{bill_id:int|string, amount:string|int|float}> $allocations */
    public function allocateAdjustment(AccountingSupplierAdjustment $adjustment, array $allocations, User $actor): AccountingSupplierAdjustment
    {
        $this->authorization->authorize($actor, AccountingPermission::ManageSupplierCredits);

        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Add at least one bill allocation.']);
        }

        return DB::transaction(function () use ($adjustment, $allocations, $actor): AccountingSupplierAdjustment {
            $adjustment = AccountingSupplierAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            throw_if($adjustment->status !== 'approved' && $adjustment->status !== 'posted', ValidationException::withMessages(['adjustment' => 'Approve the supplier adjustment before allocating it.']));

            $existingTotal = (string) $adjustment->allocations()->sum('amount');
            $newTotal = '0.00';
            $validated = [];

            foreach ($allocations as $allocation) {
                $amount = self::decimal($allocation['amount'] ?? '', 2);
                $bill = AccountingSupplierBill::query()->lockForUpdate()->findOrFail((int) ($allocation['bill_id'] ?? 0));

                throw_if($bill->accounting_book_id !== $adjustment->accounting_book_id || $bill->supplier_id !== $adjustment->supplier_id, ValidationException::withMessages(['allocations' => 'An adjustment can only be allocated to bills for the same supplier and accounting book.']));
                throw_if($bill->status !== 'approved' && $bill->status !== 'posted', ValidationException::withMessages(['allocations' => 'Only approved or posted bills can receive an approved adjustment allocation.']));
                throw_if(strtoupper($bill->currency) !== strtoupper($adjustment->currency), ValidationException::withMessages(['allocations' => 'Bill and adjustment currencies must match.']));

                $sameAllocation = $adjustment->allocations()->where('bill_id', $bill->id)->first();
                if ($sameAllocation !== null) {
                    throw_if(bccomp((string) $sameAllocation->amount, $amount, 2) !== 0, ValidationException::withMessages(['allocations' => 'A bill allocation already exists with a different amount.']));

                    continue;
                }

                $newTotal = bcadd($newTotal, $amount, 2);
                $validated[] = ['bill' => $bill, 'amount' => $amount];
            }

            throw_if(bccomp(bcadd($existingTotal, $newTotal, 2), (string) $adjustment->amount, 2) > 0, ValidationException::withMessages(['allocations' => 'Allocations cannot exceed the supplier adjustment amount.']));

            foreach ($validated as $allocation) {
                $openAmount = $this->billOpenAmount($allocation['bill']);
                $direction = $adjustment->kind === 'credit' ? 1 : -1;
                if ($direction > 0) {
                    throw_if(bccomp($allocation['amount'], $openAmount, 2) > 0, ValidationException::withMessages(['allocations' => 'A credit cannot exceed the bill open amount.']));
                }

                AccountingSupplierAdjustmentAllocation::query()->create([
                    'adjustment_id' => $adjustment->id,
                    'bill_id' => $allocation['bill']->id,
                    'amount' => $allocation['amount'],
                ]);
            }

            $this->activity->log('accounting.supplier_adjustment_allocated', $actor, $adjustment, [
                'allocation_count' => count($validated),
                'kind' => $adjustment->kind,
            ]);

            return $adjustment->load('allocations');
        }, 3);
    }

    public function approveBill(AccountingSupplierBill $bill, User $actor): AccountingSupplierBill
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);

        return DB::transaction(function () use ($bill, $actor): AccountingSupplierBill {
            $bill = AccountingSupplierBill::query()->lockForUpdate()->findOrFail($bill->id);
            throw_if($bill->status !== 'draft', ValidationException::withMessages(['bill' => 'Only draft bills can be approved.']));
            $bill->forceFill(['status' => 'approved', 'approved_by_user_id' => $actor->id, 'approved_at' => now()])->save();
            $this->activity->log('accounting.supplier_bill_approved', $actor, $bill);

            return $bill;
        });
    }

    public function approvePayment(AccountingSupplierPayment $payment, User $actor): AccountingSupplierPayment
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);

        return DB::transaction(function () use ($payment): AccountingSupplierPayment {
            $payment = AccountingSupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            throw_if($payment->status !== 'draft', ValidationException::withMessages(['payment' => 'Only draft payments can be approved.']));
            $payment->update(['status' => 'approved']);

            return $payment;
        });
    }

    public function approveAdjustment(AccountingSupplierAdjustment $adjustment, User $actor): AccountingSupplierAdjustment
    {
        $this->authorization->authorize($actor, AccountingPermission::ApproveSupplierPayables);

        return DB::transaction(function () use ($adjustment): AccountingSupplierAdjustment {
            $adjustment = AccountingSupplierAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            throw_if($adjustment->status !== 'draft', ValidationException::withMessages(['adjustment' => 'Only draft adjustments can be approved.']));
            $adjustment->update(['status' => 'approved']);

            return $adjustment;
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

    public function supplierAgeing(AccountingBook $book, int $supplierId, string $asOfDate, User $actor): LengthAwarePaginator
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $asOfDate)->startOfDay();
        $statuses = ['approved', 'posted'];
        $payments = DB::table('accounting_supplier_payment_allocations as allocation')
            ->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->where('payment.accounting_book_id', $book->id)
            ->where('payment.supplier_id', $supplierId)
            ->whereIn('payment.status', $statuses)
            ->whereDate('payment.payment_date', '<=', $asOfDate)
            ->select('allocation.bill_id')
            ->selectRaw('SUM(allocation.amount) AS allocated_total')
            ->groupBy('allocation.bill_id');

        $adjustmentTotals = [];
        foreach (['credit', 'debit'] as $kind) {
            $adjustmentTotals[$kind] = DB::table('accounting_supplier_adjustment_allocations as allocation')
                ->join('accounting_supplier_adjustments as adjustment', 'adjustment.id', '=', 'allocation.adjustment_id')
                ->where('adjustment.accounting_book_id', $book->id)
                ->where('adjustment.supplier_id', $supplierId)
                ->where('adjustment.kind', $kind)
                ->whereIn('adjustment.status', $statuses)
                ->whereDate('adjustment.adjustment_date', '<=', $asOfDate)
                ->select('allocation.bill_id')
                ->selectRaw('SUM(allocation.amount) AS allocated_total')
                ->groupBy('allocation.bill_id');
        }

        $openAmountExpression = '(bill.grand_total - COALESCE(payment_allocations.allocated_total, 0) - COALESCE(credit_allocations.allocated_total, 0) + COALESCE(debit_allocations.allocated_total, 0))';
        $paginator = DB::table('accounting_supplier_bills as bill')
            ->leftJoinSub($payments, 'payment_allocations', 'payment_allocations.bill_id', '=', 'bill.id')
            ->leftJoinSub($adjustmentTotals['credit'], 'credit_allocations', 'credit_allocations.bill_id', '=', 'bill.id')
            ->leftJoinSub($adjustmentTotals['debit'], 'debit_allocations', 'debit_allocations.bill_id', '=', 'bill.id')
            ->where('bill.accounting_book_id', $book->id)
            ->where('bill.supplier_id', $supplierId)
            ->whereIn('bill.status', $statuses)
            ->whereDate('bill.bill_date', '<=', $asOfDate)
            ->whereRaw("{$openAmountExpression} > 0")
            ->select('bill.*')
            ->selectRaw("{$openAmountExpression} AS outstanding_amount")
            ->orderBy('bill.due_date')
            ->orderBy('bill.id')
            ->paginate(50, ['*'], 'ageingPage');

        $paginator->getCollection()->transform(function (object $bill) use ($asOf): object {
            $bill->outstanding_amount = bcadd((string) $bill->outstanding_amount, '0', 2);
            $bill->due_date = $bill->due_date ? CarbonImmutable::parse($bill->due_date)->startOfDay() : null;
            $daysOverdue = $bill->due_date && $bill->due_date->lt($asOf)
                ? (int) $bill->due_date->diffInDays($asOf)
                : 0;
            $bill->days_overdue = $daysOverdue;
            $bill->age_bucket = match (true) {
                $bill->due_date === null => 'Due date unavailable',
                $daysOverdue === 0 => 'Current',
                $daysOverdue <= 30 => '1–30',
                $daysOverdue <= 60 => '31–60',
                $daysOverdue <= 90 => '61–90',
                default => '> 90',
            };

            return $bill;
        });

        return $paginator;
    }

    /** @return array<string, mixed> */
    public function reconciliation(AccountingBook $book, string $asOfDate, User $actor, ?int $supplierId = null): array
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $asOfDate)->toDateString();
        $statuses = ['approved', 'posted'];
        $billsQuery = AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->whereIn('status', $statuses)->whereDate('bill_date', '<=', $asOf);
        $paymentsQuery = DB::table('accounting_supplier_payment_allocations as allocation')->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')->where('payment.accounting_book_id', $book->id)->whereIn('payment.status', $statuses)->whereDate('payment.payment_date', '<=', $asOf);
        $adjustmentsQuery = AccountingSupplierAdjustment::query()->where('accounting_book_id', $book->id)->whereIn('status', $statuses)->whereDate('adjustment_date', '<=', $asOf);
        $allocatedCreditsQuery = DB::table('accounting_supplier_adjustment_allocations as allocation')->join('accounting_supplier_adjustments as adjustment', 'adjustment.id', '=', 'allocation.adjustment_id')->where('adjustment.accounting_book_id', $book->id)->where('adjustment.kind', 'credit')->whereIn('adjustment.status', $statuses)->whereDate('adjustment.adjustment_date', '<=', $asOf);
        foreach ([$billsQuery, $paymentsQuery, $adjustmentsQuery, $allocatedCreditsQuery] as $query) {
            if ($supplierId !== null) {
                $query->where('supplier_id', $supplierId);
            }
        }
        $bills = bcadd((string) $billsQuery->sum('grand_total'), '0', 2);
        $payments = bcadd((string) $paymentsQuery->sum('allocation.amount'), '0', 2);
        $credits = bcadd((string) (clone $adjustmentsQuery)->where('kind', 'credit')->sum('amount'), '0', 2);
        $debits = bcadd((string) (clone $adjustmentsQuery)->where('kind', 'debit')->sum('amount'), '0', 2);
        $allocatedCredits = bcadd((string) $allocatedCreditsQuery->sum('allocation.amount'), '0', 2);
        $unappliedCredits = bcsub($credits, $allocatedCredits, 2);
        $subledger = bcadd(bcsub(bcsub($bills, $payments, 2), $credits, 2), $debits, 2);

        return [
            'as_of_date' => $asOf,
            'currency' => strtoupper((string) $book->currency),
            'approved_bills' => $bills,
            'applied_payments' => $payments,
            'credits' => $credits,
            'allocated_credits' => $allocatedCredits,
            'unapplied_credits' => $unappliedCredits,
            'debits' => $debits,
            'subledger_balance' => $subledger,
            'gl_control_balance' => null,
            'gl_control_status' => $book->posting_enabled ? 'not_queried' : 'posting_disabled',
        ];
    }

    /** @return Collection<int, object{age_bucket:string,bill_count:int,outstanding:string}> */
    public function supplierAgeingSummary(AccountingBook $book, string $asOfDate, User $actor, ?int $supplierId = null): Collection
    {
        $this->authorization->authorize($actor, AccountingPermission::ViewPayables);
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $asOfDate)->toDateString();
        $statuses = ['approved', 'posted'];

        $payments = DB::table('accounting_supplier_payment_allocations as allocation')
            ->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->where('payment.accounting_book_id', $book->id)
            ->whereIn('payment.status', $statuses)
            ->whereDate('payment.payment_date', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('payment.supplier_id', $supplierId))
            ->select('allocation.bill_id')
            ->selectRaw('SUM(allocation.amount) AS allocated_total')
            ->groupBy('allocation.bill_id');

        $credits = DB::table('accounting_supplier_adjustment_allocations as allocation')
            ->join('accounting_supplier_adjustments as adjustment', 'adjustment.id', '=', 'allocation.adjustment_id')
            ->where('adjustment.accounting_book_id', $book->id)
            ->where('adjustment.kind', 'credit')
            ->whereIn('adjustment.status', $statuses)
            ->whereDate('adjustment.adjustment_date', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('adjustment.supplier_id', $supplierId))
            ->select('allocation.bill_id')
            ->selectRaw('SUM(allocation.amount) AS allocated_total')
            ->groupBy('allocation.bill_id');

        $debits = DB::table('accounting_supplier_adjustment_allocations as allocation')
            ->join('accounting_supplier_adjustments as adjustment', 'adjustment.id', '=', 'allocation.adjustment_id')
            ->where('adjustment.accounting_book_id', $book->id)
            ->where('adjustment.kind', 'debit')
            ->whereIn('adjustment.status', $statuses)
            ->whereDate('adjustment.adjustment_date', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('adjustment.supplier_id', $supplierId))
            ->select('allocation.bill_id')
            ->selectRaw('SUM(allocation.amount) AS allocated_total')
            ->groupBy('allocation.bill_id');

        $balance = '(bill.grand_total - COALESCE(payment_allocations.allocated_total, 0) - COALESCE(credit_allocations.allocated_total, 0) + COALESCE(debit_allocations.allocated_total, 0))';
        $daysOverdue = DB::connection()->getDriverName() === 'sqlite'
            ? 'CAST(julianday(?) - julianday(bill.due_date) AS INTEGER)'
            : 'DATEDIFF(?, bill.due_date)';
        $bucket = "CASE WHEN bill.due_date IS NULL THEN 'Due date unavailable' WHEN bill.due_date >= ? THEN 'Current' WHEN {$daysOverdue} <= 30 THEN '1–30' WHEN {$daysOverdue} <= 60 THEN '31–60' WHEN {$daysOverdue} <= 90 THEN '61–90' ELSE '> 90' END";

        $query = DB::table('accounting_supplier_bills as bill')
            ->leftJoinSub($payments, 'payment_allocations', 'payment_allocations.bill_id', '=', 'bill.id')
            ->leftJoinSub($credits, 'credit_allocations', 'credit_allocations.bill_id', '=', 'bill.id')
            ->leftJoinSub($debits, 'debit_allocations', 'debit_allocations.bill_id', '=', 'bill.id')
            ->where('bill.accounting_book_id', $book->id)
            ->whereIn('bill.status', $statuses)
            ->whereDate('bill.bill_date', '<=', $asOf)
            ->when($supplierId !== null, fn ($query) => $query->where('bill.supplier_id', $supplierId))
            ->whereRaw("{$balance} > 0")
            ->selectRaw("{$bucket} AS age_bucket, COUNT(*) AS bill_count, SUM({$balance}) AS outstanding", [$asOf, $asOf, $asOf, $asOf])
            ->groupBy('age_bucket');

        $grouped = $query->get()->keyBy('age_bucket');
        $orderedBuckets = ['Current', '1–30', '31–60', '61–90', '> 90', 'Due date unavailable'];

        return collect($orderedBuckets)->map(fn (string $name): object => (object) [
            'age_bucket' => $name,
            'bill_count' => (int) ($grouped->get($name)->bill_count ?? 0),
            'outstanding' => bcadd((string) ($grouped->get($name)->outstanding ?? '0'), '0', 2),
        ]);
    }

    private function sumBalance(AccountingBook $book, int $supplierId, array $statuses): string
    {
        $billed = (string) AccountingSupplierBill::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->whereIn('status', $statuses)->sum('grand_total');
        $credits = (string) AccountingSupplierAdjustment::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->where('kind', 'credit')->whereIn('status', $statuses)->sum('amount');
        $debits = (string) AccountingSupplierAdjustment::query()->where('accounting_book_id', $book->id)->where('supplier_id', $supplierId)->where('kind', 'debit')->whereIn('status', $statuses)->sum('amount');
        $paid = (string) DB::table('accounting_supplier_payment_allocations as allocation')
            ->join('accounting_supplier_payments as payment', 'payment.id', '=', 'allocation.payment_id')
            ->where('payment.accounting_book_id', $book->id)
            ->where('payment.supplier_id', $supplierId)
            ->whereIn('payment.status', $statuses)
            ->sum('allocation.amount');

        return bcsub(bcadd(bcsub($billed, $credits, 2), $debits, 2), $paid, 2);
    }

    private function billOpenAmount(AccountingSupplierBill $bill): string
    {
        $paid = (string) $bill->allocations()->whereHas('payment', fn ($query) => $query->whereIn('status', ['approved', 'posted'])->whereDate('payment_date', '<=', now()->toDateString()))->sum('amount');
        $credits = (string) $bill->creditAllocations()->whereHas('adjustment', fn ($query) => $query->where('kind', 'credit')->whereIn('status', ['approved', 'posted']))->sum('amount');
        $debits = (string) $bill->creditAllocations()->whereHas('adjustment', fn ($query) => $query->where('kind', 'debit')->whereIn('status', ['approved', 'posted']))->sum('amount');

        return bcadd(bcsub(bcsub((string) $bill->grand_total, $paid, 2), $credits, 2), $debits, 2);
    }

    private function assertCurrency(AccountingBook $book, string $currency): void
    {
        throw_if(
            strtoupper((string) $book->currency) !== strtoupper($currency),
            ValidationException::withMessages(['currency' => 'Currency must match the accounting book currency.']),
        );
    }

    private function assertSameBillPayload(AccountingSupplierBill $bill, array $data): void
    {
        $matches = (int) $bill->supplier_id === (int) $data['supplier_id'] && (int) ($bill->purchase_id ?? 0) === (int) ($data['purchase_id'] ?? 0) && $bill->bill_date?->format('Y-m-d') === $data['bill_date'] && strtoupper((string) $bill->currency) === strtoupper($data['currency']) && count($bill->lines) === count($data['lines']);
        if ($matches) {
            foreach ($bill->lines as $index => $line) {
                $input = $data['lines'][$index];
                $matches = $matches
                    && (string) $line->description === trim($input['description'])
                    && (string) $line->quantity === self::decimal($input['quantity'], 3)
                    && (string) $line->unit_cost === self::decimal($input['unit_cost'], 2)
                    && (string) $line->vat_rate === self::decimal($input['vat_rate'] ?? '0', 4);
            }
        }
        throw_unless($matches, ValidationException::withMessages(['idempotency_key' => 'Conflicting bill replay for this idempotency key.']));
    }

    private static function decimal(string|int|float $value, int $scale): string
    {
        $value = trim((string) $value);
        if (preg_match(sprintf('/^\\d+(?:\\.\\d{1,%d})?$/', $scale), $value) !== 1) {
            throw ValidationException::withMessages(['amount' => 'Invalid decimal value.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return implode('.', [$whole, str_pad($fraction, $scale, '0')]);
    }
}
