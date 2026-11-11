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

        $this->assertSame('45.00', $service->supplierBalance($book, $supplier->id));
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
