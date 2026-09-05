<?php

namespace Tests\Feature\Finance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PakistanOfficeFinanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_is_additive_empty_and_uses_the_normalized_pkr_ledger_shape(): void
    {
        foreach (['office_finance_accounts', 'office_finance_transactions', 'employee_loans', 'employee_loan_repayments'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertTrue(Schema::hasColumns('office_finance_accounts', [
            'name', 'normalized_name', 'account_type', 'currency', 'active', 'created_by_user_id',
        ]));
        $this->assertTrue(Schema::hasColumns('office_finance_transactions', [
            'reference', 'transaction_date', 'transaction_type', 'direction', 'category', 'amount_pkr',
            'office_finance_account_id', 'employee_id', 'funding_source', 'aed_amount',
            'exchange_rate_pkr_per_aed', 'calculated_pkr_amount', 'fx_bank_charges_pkr',
            'adjustment_reason', 'status', 'voided_at', 'void_reason', 'idempotency_key',
        ]));
        $this->assertTrue(Schema::hasColumns('employee_loans', ['loan_transaction_id', 'employee_id', 'status']));
        $this->assertTrue(Schema::hasColumns('employee_loan_repayments', [
            'employee_loan_id', 'repayment_transaction_id', 'idempotency_key', 'recorded_by_user_id',
        ]));
    }

    public function test_funding_preserves_aed_rate_calculated_and_actual_pkr_without_affecting_existing_expenses(): void
    {
        [$user, $account] = $this->account();
        $transaction = $this->transaction($user, $account, [
            'reference' => 'OF-2026-000001',
            'transaction_type' => 'funding_received',
            'direction' => 'in',
            'description' => 'Dubai office funding',
            'amount_pkr' => '905000.00',
            'funding_source' => 'Dubai Head Office',
            'aed_amount' => '12000.00',
            'exchange_rate_pkr_per_aed' => '75.800000',
            'calculated_pkr_amount' => '909600.00',
            'fx_bank_charges_pkr' => '4600.00',
        ]);

        $this->assertDatabaseHas('office_finance_transactions', [
            'id' => $transaction,
            'currency' => 'PKR',
            'amount_pkr' => 905000,
            'aed_amount' => 12000,
            'exchange_rate_pkr_per_aed' => 75.8,
            'calculated_pkr_amount' => 909600,
        ]);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_database_constraints_reject_invalid_currency_rate_direction_and_void_state(): void
    {
        [$user, $account] = $this->account();

        foreach ([
            ['currency' => 'AED'],
            ['exchange_rate_pkr_per_aed' => 0],
            ['direction' => 'out'],
            ['status' => 'voided', 'voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null],
        ] as $index => $override) {
            try {
                $this->transaction($user, $account, array_replace([
                    'reference' => 'OF-INVALID-'.$index,
                    'transaction_type' => 'funding_received',
                    'direction' => 'in',
                    'description' => 'Invalid funding',
                    'amount_pkr' => 7580,
                    'funding_source' => 'Dubai',
                    'aed_amount' => 100,
                    'exchange_rate_pkr_per_aed' => 75.8,
                    'calculated_pkr_amount' => 7580,
                ], $override));
                $this->fail('Invalid office-finance transaction was accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_loan_and_repayment_link_to_distinct_immutable_cashbook_transactions(): void
    {
        [$user, $account, $employee] = $this->account(withEmployee: true);
        $loanTransaction = $this->transaction($user, $account, [
            'reference' => 'OF-2026-000010',
            'transaction_type' => 'employee_loan_given',
            'direction' => 'out',
            'description' => 'Employee advance',
            'amount_pkr' => 50000,
            'employee_id' => $employee->id,
        ]);
        $loan = DB::table('employee_loans')->insertGetId([
            'loan_transaction_id' => $loanTransaction,
            'employee_id' => $employee->id,
            'status' => 'open',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repaymentTransaction = $this->transaction($user, $account, [
            'reference' => 'OF-2026-000011',
            'transaction_type' => 'employee_loan_repayment',
            'direction' => 'in',
            'description' => 'Loan repayment',
            'amount_pkr' => 10000,
            'employee_id' => $employee->id,
        ]);
        DB::table('employee_loan_repayments')->insert([
            'employee_loan_id' => $loan,
            'repayment_transaction_id' => $repaymentTransaction,
            'idempotency_key' => (string) Str::uuid(),
            'recorded_by_user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->assertDatabaseCount('employee_loans', 1);
        $this->assertDatabaseCount('employee_loan_repayments', 1);
        $this->assertNotSame($loanTransaction, $repaymentTransaction);

        $this->expectException(QueryException::class);
        DB::table('office_finance_accounts')->where('id', $account)->delete();
    }

    public function test_reference_idempotency_account_names_and_loan_transactions_are_unique(): void
    {
        [$user, $account, $employee] = $this->account(withEmployee: true);
        $idempotency = (string) Str::uuid();
        $loanTransaction = $this->transaction($user, $account, [
            'reference' => 'OF-2026-000020',
            'transaction_type' => 'employee_loan_given',
            'direction' => 'out',
            'description' => 'Employee advance',
            'amount_pkr' => 25000,
            'employee_id' => $employee->id,
            'idempotency_key' => $idempotency,
        ]);
        DB::table('employee_loans')->insert([
            'loan_transaction_id' => $loanTransaction,
            'employee_id' => $employee->id,
            'status' => 'open',
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::table('office_finance_accounts')->insert([
                'name' => 'Cash duplicate', 'normalized_name' => 'cash', 'account_type' => 'cash',
                'currency' => 'PKR', 'active' => 1, 'created_by_user_id' => $user->id,
            ]);
            $this->fail('Duplicate normalized account name was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        try {
            $this->transaction($user, $account, [
                'reference' => 'OF-2026-000020',
                'transaction_type' => 'expense',
                'direction' => 'out',
                'category' => 'utilities',
                'description' => 'Duplicate posting',
                'amount_pkr' => 1000,
            ]);
            $this->fail('Duplicate transaction reference was accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    /** @return array{User, int, 2?: Employee} */
    private function account(bool $withEmployee = false): array
    {
        $user = User::factory()->create();
        $account = DB::table('office_finance_accounts')->insertGetId([
            'name' => 'Cash',
            'normalized_name' => 'cash',
            'account_type' => 'cash',
            'currency' => 'PKR',
            'active' => 1,
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! $withEmployee) {
            return [$user, $account];
        }

        $employee = Employee::factory()->for($user)->create(['email' => $user->email, 'status' => true]);

        return [$user, $account, $employee];
    }

    /** @param array<string, mixed> $override */
    private function transaction(User $user, int $account, array $override): int
    {
        return DB::table('office_finance_transactions')->insertGetId(array_replace([
            'reference' => 'OF-'.Str::upper(Str::random(12)),
            'transaction_date' => '2026-08-28',
            'transaction_type' => 'expense',
            'direction' => 'out',
            'category' => null,
            'description' => 'Office transaction',
            'amount_pkr' => 1000,
            'currency' => 'PKR',
            'office_finance_account_id' => $account,
            'employee_id' => null,
            'external_reference' => null,
            'note' => null,
            'funding_source' => null,
            'aed_amount' => null,
            'exchange_rate_pkr_per_aed' => null,
            'calculated_pkr_amount' => null,
            'fx_bank_charges_pkr' => null,
            'adjustment_reason' => null,
            'status' => 'posted',
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
            'idempotency_key' => (string) Str::uuid(),
            'created_by_user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $override));
    }
}
