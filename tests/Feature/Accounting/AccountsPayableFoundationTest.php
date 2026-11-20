<?php

namespace Tests\Feature\Accounting;

use App\Enums\EmployeeRole;
use App\Models\AccountingBook;
use App\Models\Employee;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountsPayableFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_bill_is_draft_only_idempotent_and_balance_reconciles_after_allocation(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $key = (string) Str::uuid();

        $payload = [
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_number' => 'SUP-1001',
            'bill_date' => '2026-11-11',
            'due_date' => '2026-12-11',
            'currency' => 'AED',
            'idempotency_key' => $key,
            'lines' => [[
                'description' => 'Laptop',
                'quantity' => 1,
                'unit_cost' => '100.00',
                'vat_rate' => '5',
            ]],
        ];

        $bill = $service->createDraftBill($payload, $owner);
        $retry = $service->createDraftBill($payload, $owner);

        $this->assertSame($bill->id, $retry->id);
        $this->assertSame('105.00', (string) $bill->grand_total);
        $this->assertSame('draft', $bill->status);

        $payment = $service->recordDraftPayment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'payment_date' => '2026-11-12',
            'currency' => 'AED',
            'amount' => '60.00',
            'reference' => 'PAY-1001',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $service->allocatePayment($payment, [['bill_id' => $bill->id, 'amount' => '60.00']], $owner);

        $this->assertSame('45.00', $service->draftExposure($book, $supplier->id, $owner));
        $this->assertFalse($book->refresh()->posting_enabled);
    }

    public function test_staff_cannot_create_supplier_bill_without_b2_permission(): void
    {
        $staff = $this->user(EmployeeRole::Staff);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(AccountsPayableService::class)->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Laptop', 'quantity' => 1, 'unit_cost' => '100.00']],
        ], $staff);
    }

    public function test_supplier_credit_is_draft_only_and_reduces_outstanding_balance(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);

        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Laptop', 'quantity' => 1, 'unit_cost' => '100.00']],
        ], $owner);

        $credit = $service->recordDraftAdjustment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_id' => $bill->id,
            'kind' => 'credit',
            'adjustment_date' => '2026-11-11',
            'currency' => 'AED',
            'amount' => '20.00',
            'reference' => 'CN-1001',
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Supplier credit for returned item',
        ], $owner);

        $this->assertSame('draft', $credit->status);
        $this->assertSame('80.00', $service->draftExposure($book, $supplier->id, $owner));
        $this->assertFalse($book->refresh()->posting_enabled);
    }

    public function test_payment_cannot_allocate_more_than_bill_total(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Laptop', 'quantity' => 1, 'unit_cost' => '100.00']],
        ], $owner);
        $payment = $service->recordDraftPayment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'payment_date' => '2026-11-11',
            'currency' => 'AED',
            'amount' => '200.00',
            'reference' => 'PAY-1002',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->expectException(ValidationException::class);
        $service->allocatePayment($payment, [['bill_id' => $bill->id, 'amount' => '200.00']], $owner);
    }

    public function test_conflicting_idempotency_replay_is_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $key = (string) Str::uuid();
        $payload = [
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => $key,
            'lines' => [['description' => 'Laptop', 'quantity' => '1', 'unit_cost' => '100.00']],
        ];

        $service->createDraftBill($payload, $owner);

        $this->expectException(ValidationException::class);
        $service->createDraftBill([...$payload, 'lines' => [['description' => 'Laptop', 'quantity' => '2', 'unit_cost' => '100.00']]], $owner);
    }

    public function test_negative_allocations_are_rejected(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Laptop', 'quantity' => '1', 'unit_cost' => '100.00']],
        ], $owner);
        $payment = $service->recordDraftPayment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'payment_date' => '2026-11-11',
            'currency' => 'AED',
            'amount' => '100.00',
            'reference' => 'PAY-NEG',
            'idempotency_key' => (string) Str::uuid(),
        ], $owner);

        $this->expectException(ValidationException::class);
        $service->allocatePayment($payment, [['bill_id' => $bill->id, 'amount' => '-1.00']], $owner);
    }

    public function test_approved_balance_excludes_drafts_until_approval(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_date' => '2026-11-11',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Laptop', 'quantity' => '1', 'unit_cost' => '100.00']],
        ], $owner);

        $this->assertSame('0.00', $service->supplierBalance($book, $supplier->id, $owner));
        $service->approveBill($bill, $owner);
        $this->assertSame('100.00', $service->supplierBalance($book, $supplier->id, $owner));
        $this->assertFalse($book->refresh()->posting_enabled);
    }

    public function test_quickbooks_verified_ap_bridge_reconciles_payments_and_allocated_credit_without_posting(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $book = AccountingBook::query()->firstOrFail();
        $supplier = Supplier::factory()->create();
        $service = app(AccountsPayableService::class);
        $bill = $service->createDraftBill([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_number' => 'QB-AP-25',
            'bill_date' => '2026-11-16',
            'due_date' => '2026-11-26',
            'currency' => 'AED',
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [['description' => 'Expense', 'quantity' => '1', 'unit_cost' => '25.00']],
        ], $owner);
        $service->approveBill($bill, $owner);

        foreach (['10.00', '10.00'] as $index => $amount) {
            $payment = $service->recordDraftPayment([
                'accounting_book_id' => $book->id,
                'supplier_id' => $supplier->id,
                'payment_date' => '2026-11-16',
                'currency' => 'AED',
                'amount' => $amount,
                'reference' => 'QB-AP-PAY-'.($index + 1),
                'idempotency_key' => (string) Str::uuid(),
            ], $owner);
            $service->allocatePayment($payment, [['bill_id' => $bill->id, 'amount' => $amount]], $owner);
            $service->approvePayment($payment, $owner);
        }

        $credit = $service->recordDraftAdjustment([
            'accounting_book_id' => $book->id,
            'supplier_id' => $supplier->id,
            'bill_id' => $bill->id,
            'kind' => 'credit',
            'adjustment_date' => '2026-11-16',
            'currency' => 'AED',
            'amount' => '5.00',
            'reference' => 'QB-AP-CREDIT',
            'idempotency_key' => (string) Str::uuid(),
            'reason' => 'Verified synthetic credit application',
        ], $owner);
        $service->approveAdjustment($credit, $owner);
        $service->allocateAdjustment($credit, [['bill_id' => $bill->id, 'amount' => '5.00']], $owner);

        $reconciliation = $service->reconciliation($book, '2026-11-16', $owner, $supplier->id);
        $this->assertSame('25.00', $reconciliation['approved_bills']);
        $this->assertSame('20.00', $reconciliation['applied_payments']);
        $this->assertSame('5.00', $reconciliation['credits']);
        $this->assertSame('5.00', $reconciliation['allocated_credits']);
        $this->assertSame('0.00', $reconciliation['unapplied_credits']);
        $this->assertSame('0.00', $reconciliation['subledger_balance']);
        $this->assertNull($reconciliation['gl_control_balance'], 'Posting-disabled foundation must not assert a local GL tie-out.');
        $this->assertSame('posting_disabled', $reconciliation['gl_control_status']);
        $this->assertSame('0.00', $service->supplierBalance($book, $supplier->id, $owner));
        $summary = $service->supplierAgeingSummary($book, '2026-11-16', $owner, $supplier->id);
        $this->assertSame(0, $summary->sum('bill_count'), 'Fully settled AP bills must not appear in the open aging summary.');
        $ageing = $service->supplierAgeing($book, $supplier->id, '2026-11-16', $owner);
        $this->assertSame(0, $ageing->total(), 'Bill-level aging must use the same allocations as summary aging.');
        $this->assertFalse($book->refresh()->posting_enabled);
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create([
            'role' => $role,
            'status' => true,
            'email' => $user->email,
        ]);

        return $user;
    }
}
