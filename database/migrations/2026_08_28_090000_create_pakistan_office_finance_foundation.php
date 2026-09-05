<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteTables();

            return;
        }

        $this->createMysqlTables();
    }

    public function down(): void
    {
        foreach (['employee_loan_repayments', 'employee_loans', 'office_finance_transactions', 'office_finance_accounts'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Rollback refused: Pakistan Office Finance records exist.');
            }
        }

        Schema::dropIfExists('employee_loan_repayments');
        Schema::dropIfExists('employee_loans');
        Schema::dropIfExists('office_finance_transactions');
        Schema::dropIfExists('office_finance_accounts');
    }

    private function createMysqlTables(): void
    {
        Schema::create('office_finance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique('of_accounts_normalized_name_uq');
            $table->string('account_type', 24);
            $table->char('currency', 3)->default('PKR');
            $table->boolean('active')->default(true);
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by_user_id', 'of_accounts_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'of_accounts_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['active', 'account_type'], 'of_accounts_active_type_idx');
        });
        DB::statement("ALTER TABLE office_finance_accounts ADD CONSTRAINT of_accounts_values_chk CHECK (account_type IN ('cash','bank','company_card','other') AND currency = 'PKR')");

        Schema::create('office_finance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique('of_transactions_reference_uq');
            $table->date('transaction_date');
            $table->string('transaction_type', 32);
            $table->string('direction', 3);
            $table->string('category', 32)->nullable();
            $table->string('description', 500);
            $table->decimal('amount_pkr', 16, 2);
            $table->char('currency', 3)->default('PKR');
            $table->foreignId('office_finance_account_id');
            $table->foreignId('employee_id')->nullable();
            $table->string('external_reference', 190)->nullable();
            $table->text('note')->nullable();
            $table->string('funding_source', 190)->nullable();
            $table->decimal('aed_amount', 16, 2)->nullable();
            $table->decimal('exchange_rate_pkr_per_aed', 12, 6)->nullable();
            $table->decimal('calculated_pkr_amount', 16, 2)->nullable();
            $table->decimal('fx_bank_charges_pkr', 16, 2)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->string('status', 16)->default('posted');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable();
            $table->text('void_reason')->nullable();
            $table->uuid('idempotency_key')->unique('of_transactions_idempotency_uq');
            $table->foreignId('created_by_user_id');
            $table->timestamps();

            $table->foreign('office_finance_account_id', 'of_transactions_account_fk')->references('id')->on('office_finance_accounts')->restrictOnDelete();
            $table->foreign('employee_id', 'of_transactions_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('voided_by_user_id', 'of_transactions_voided_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'of_transactions_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['office_finance_account_id', 'transaction_date', 'status'], 'of_transactions_account_date_idx');
            $table->index(['transaction_type', 'transaction_date', 'status'], 'of_transactions_type_date_idx');
            $table->index(['category', 'transaction_date', 'status'], 'of_transactions_category_date_idx');
            $table->index(['employee_id', 'transaction_date', 'status'], 'of_transactions_employee_date_idx');
            $table->index(['transaction_date', 'id'], 'of_transactions_ledger_idx');
        });
        $this->addMysqlTransactionChecks();

        Schema::create('employee_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_transaction_id')->unique('employee_loans_transaction_uq');
            $table->foreignId('employee_id');
            $table->string('status', 24)->default('open');
            $table->foreignId('created_by_user_id');
            $table->foreignId('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('loan_transaction_id', 'employee_loans_transaction_fk')->references('id')->on('office_finance_transactions')->restrictOnDelete();
            $table->foreign('employee_id', 'employee_loans_employee_fk')->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('created_by_user_id', 'employee_loans_created_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_id', 'employee_loans_updated_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['employee_id', 'status'], 'employee_loans_employee_status_idx');
            $table->index(['status', 'created_at'], 'employee_loans_status_created_idx');
        });
        DB::statement("ALTER TABLE employee_loans ADD CONSTRAINT employee_loans_status_chk CHECK (status IN ('open','partially_repaid','repaid','voided'))");

        Schema::create('employee_loan_repayments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_loan_id');
            $table->foreignId('repayment_transaction_id')->unique('employee_loan_repayments_transaction_uq');
            $table->uuid('idempotency_key')->unique('employee_loan_repayments_idempotency_uq');
            $table->foreignId('recorded_by_user_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('employee_loan_id', 'employee_loan_repayments_loan_fk')->references('id')->on('employee_loans')->restrictOnDelete();
            $table->foreign('repayment_transaction_id', 'employee_loan_repayments_transaction_fk')->references('id')->on('office_finance_transactions')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'employee_loan_repayments_recorded_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['employee_loan_id', 'created_at'], 'employee_loan_repayments_loan_created_idx');
        });
    }

    private function addMysqlTransactionChecks(): void
    {
        DB::statement("ALTER TABLE office_finance_transactions ADD CONSTRAINT of_transactions_values_chk CHECK (
            transaction_type IN ('expense','funding_received','employee_loan_given','employee_loan_repayment','adjustment')
            AND direction IN ('in','out')
            AND status IN ('posted','voided')
            AND currency = 'PKR'
            AND amount_pkr > 0
            AND (category IS NULL OR category IN ('salaries','advertising','grocery','courier','utilities','office_rent','transportation','repairs','internet_telecom','office_supplies','miscellaneous'))
            AND ((transaction_type = 'expense' AND category IS NOT NULL) OR transaction_type <> 'expense')
            AND ((transaction_type IN ('expense','employee_loan_given') AND direction = 'out')
                OR (transaction_type IN ('funding_received','employee_loan_repayment') AND direction = 'in')
                OR transaction_type = 'adjustment')
            AND ((transaction_type IN ('employee_loan_given','employee_loan_repayment') AND employee_id IS NOT NULL)
                OR transaction_type NOT IN ('employee_loan_given','employee_loan_repayment'))
            AND ((transaction_type = 'funding_received' AND funding_source IS NOT NULL AND aed_amount > 0
                    AND exchange_rate_pkr_per_aed > 0 AND calculated_pkr_amount > 0
                    AND (fx_bank_charges_pkr IS NULL OR fx_bank_charges_pkr >= 0))
                OR (transaction_type <> 'funding_received' AND funding_source IS NULL AND aed_amount IS NULL
                    AND exchange_rate_pkr_per_aed IS NULL AND calculated_pkr_amount IS NULL AND fx_bank_charges_pkr IS NULL))
            AND ((transaction_type = 'adjustment' AND adjustment_reason IS NOT NULL AND CHAR_LENGTH(TRIM(adjustment_reason)) > 0)
                OR (transaction_type <> 'adjustment' AND adjustment_reason IS NULL))
            AND ((status = 'posted' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
                OR (status = 'voided' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL
                    AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0))
        )");
    }

    private function createSqliteTables(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE office_finance_accounts (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name VARCHAR(120) NOT NULL,
 normalized_name VARCHAR(120) NOT NULL UNIQUE,
 account_type VARCHAR(24) NOT NULL CHECK(account_type IN ('cash','bank','company_card','other')),
 currency CHAR(3) NOT NULL DEFAULT 'PKR' CHECK(currency = 'PKR'),
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 description VARCHAR(500) NULL,
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX of_accounts_active_type_idx ON office_finance_accounts(active,account_type)');

        DB::statement(<<<'SQL'
CREATE TABLE office_finance_transactions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 reference VARCHAR(32) NOT NULL UNIQUE,
 transaction_date DATE NOT NULL,
 transaction_type VARCHAR(32) NOT NULL CHECK(transaction_type IN ('expense','funding_received','employee_loan_given','employee_loan_repayment','adjustment')),
 direction VARCHAR(3) NOT NULL CHECK(direction IN ('in','out')),
 category VARCHAR(32) NULL CHECK(category IS NULL OR category IN ('salaries','advertising','grocery','courier','utilities','office_rent','transportation','repairs','internet_telecom','office_supplies','miscellaneous')),
 description VARCHAR(500) NOT NULL,
 amount_pkr NUMERIC(16,2) NOT NULL CHECK(amount_pkr > 0),
 currency CHAR(3) NOT NULL DEFAULT 'PKR' CHECK(currency = 'PKR'),
 office_finance_account_id INTEGER NOT NULL,
 employee_id INTEGER NULL,
 external_reference VARCHAR(190) NULL,
 note TEXT NULL,
 funding_source VARCHAR(190) NULL,
 aed_amount NUMERIC(16,2) NULL,
 exchange_rate_pkr_per_aed NUMERIC(12,6) NULL,
 calculated_pkr_amount NUMERIC(16,2) NULL,
 fx_bank_charges_pkr NUMERIC(16,2) NULL,
 adjustment_reason TEXT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'posted' CHECK(status IN ('posted','voided')),
 voided_at DATETIME NULL,
 voided_by_user_id INTEGER NULL,
 void_reason TEXT NULL,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 created_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 CHECK((transaction_type = 'expense' AND category IS NOT NULL) OR transaction_type <> 'expense'),
 CHECK((transaction_type IN ('expense','employee_loan_given') AND direction = 'out')
    OR (transaction_type IN ('funding_received','employee_loan_repayment') AND direction = 'in')
    OR transaction_type = 'adjustment'),
 CHECK((transaction_type IN ('employee_loan_given','employee_loan_repayment') AND employee_id IS NOT NULL)
    OR transaction_type NOT IN ('employee_loan_given','employee_loan_repayment')),
 CHECK((transaction_type = 'funding_received' AND funding_source IS NOT NULL AND aed_amount > 0
        AND exchange_rate_pkr_per_aed > 0 AND calculated_pkr_amount > 0
        AND (fx_bank_charges_pkr IS NULL OR fx_bank_charges_pkr >= 0))
    OR (transaction_type <> 'funding_received' AND funding_source IS NULL AND aed_amount IS NULL
        AND exchange_rate_pkr_per_aed IS NULL AND calculated_pkr_amount IS NULL AND fx_bank_charges_pkr IS NULL)),
 CHECK((transaction_type = 'adjustment' AND adjustment_reason IS NOT NULL AND LENGTH(TRIM(adjustment_reason)) > 0)
    OR (transaction_type <> 'adjustment' AND adjustment_reason IS NULL)),
 CHECK((status = 'posted' AND voided_at IS NULL AND voided_by_user_id IS NULL AND void_reason IS NULL)
    OR (status = 'voided' AND voided_at IS NOT NULL AND voided_by_user_id IS NOT NULL
        AND void_reason IS NOT NULL AND LENGTH(TRIM(void_reason)) > 0)),
 FOREIGN KEY(office_finance_account_id) REFERENCES office_finance_accounts(id) ON DELETE RESTRICT,
 FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(voided_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX of_transactions_account_date_idx ON office_finance_transactions(office_finance_account_id,transaction_date,status)');
        DB::statement('CREATE INDEX of_transactions_type_date_idx ON office_finance_transactions(transaction_type,transaction_date,status)');
        DB::statement('CREATE INDEX of_transactions_category_date_idx ON office_finance_transactions(category,transaction_date,status)');
        DB::statement('CREATE INDEX of_transactions_employee_date_idx ON office_finance_transactions(employee_id,transaction_date,status)');
        DB::statement('CREATE INDEX of_transactions_ledger_idx ON office_finance_transactions(transaction_date,id)');

        DB::statement(<<<'SQL'
CREATE TABLE employee_loans (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 loan_transaction_id INTEGER NOT NULL UNIQUE,
 employee_id INTEGER NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'open' CHECK(status IN ('open','partially_repaid','repaid','voided')),
 created_by_user_id INTEGER NOT NULL,
 updated_by_user_id INTEGER NULL,
 created_at DATETIME NULL,
 updated_at DATETIME NULL,
 FOREIGN KEY(loan_transaction_id) REFERENCES office_finance_transactions(id) ON DELETE RESTRICT,
 FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX employee_loans_employee_status_idx ON employee_loans(employee_id,status)');
        DB::statement('CREATE INDEX employee_loans_status_created_idx ON employee_loans(status,created_at)');

        DB::statement(<<<'SQL'
CREATE TABLE employee_loan_repayments (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 employee_loan_id INTEGER NOT NULL,
 repayment_transaction_id INTEGER NOT NULL UNIQUE,
 idempotency_key VARCHAR(36) NOT NULL UNIQUE,
 recorded_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL,
 FOREIGN KEY(employee_loan_id) REFERENCES employee_loans(id) ON DELETE RESTRICT,
 FOREIGN KEY(repayment_transaction_id) REFERENCES office_finance_transactions(id) ON DELETE RESTRICT,
 FOREIGN KEY(recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX employee_loan_repayments_loan_created_idx ON employee_loan_repayments(employee_loan_id,created_at)');
    }
};
