<?php

use App\Enums\AccountingAccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_books', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 160);
            $table->string('legal_name', 190);
            $table->char('currency', 3)->default('AED');
            $table->char('country_code', 2)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('posting_enabled')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('accounting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
            $table->string('number', 24);
            $table->string('name', 160);
            $table->string('account_type', 24);
            $table->string('normal_balance', 6);
            $table->boolean('is_control')->default(false);
            $table->boolean('is_posting')->default(true);
            $table->boolean('active')->default(true);
            $table->boolean('protected')->default(false);
            $table->timestamps();
            $table->unique(['accounting_book_id', 'number']);
            $table->index(['accounting_book_id', 'account_type', 'active']);
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 12)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'starts_on', 'ends_on']);
            $table->index(['accounting_book_id', 'status', 'starts_on', 'ends_on']);
        });

        Schema::create('accounting_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accounting_book_id')->constrained('accounting_books')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();
            $table->string('reference', 50);
            $table->date('posting_date');
            $table->string('status', 12)->default('draft');
            $table->string('narration', 500);
            $table->uuid('idempotency_key');
            $table->string('source_type', 120)->nullable();
            $table->string('source_key', 190)->nullable();
            $table->foreignId('reversal_of_journal_id')->nullable()->constrained('accounting_journals')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_book_id', 'reference']);
            $table->unique(['accounting_book_id', 'idempotency_key']);
            $table->unique(['accounting_book_id', 'source_type', 'source_key']);
            $table->index(['accounting_book_id', 'posting_date', 'status']);
        });

        Schema::create('accounting_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('accounting_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->unsignedInteger('line_number');
            $table->string('description', 250)->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->unique(['journal_id', 'line_number']);
            $table->index(['account_id', 'journal_id']);
        });

        $bookId = DB::table('accounting_books')->insertGetId([
            'code' => 'TPZ-UAE',
            'name' => 'TPZ UAE General Ledger',
            'legal_name' => 'Tech Point Zone Electronics Trading LLC',
            'currency' => 'AED',
            'country_code' => 'AE',
            'active' => true,
            'posting_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accounts = [
            ['number' => '1000', 'name' => 'Assets', 'type' => AccountingAccountType::Asset->value, 'normal' => 'debit', 'posting' => false],
            ['number' => '1100', 'name' => 'Cash and Bank', 'type' => AccountingAccountType::Asset->value, 'normal' => 'debit', 'posting' => true],
            ['number' => '1200', 'name' => 'Accounts Receivable', 'type' => AccountingAccountType::Asset->value, 'normal' => 'debit', 'posting' => true],
            ['number' => '1300', 'name' => 'Inventory', 'type' => AccountingAccountType::Asset->value, 'normal' => 'debit', 'posting' => true],
            ['number' => '2000', 'name' => 'Liabilities', 'type' => 'liability', 'normal' => 'credit', 'posting' => false],
            ['number' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal' => 'credit', 'posting' => true],
            ['number' => '2200', 'name' => 'VAT Payable', 'type' => 'liability', 'normal' => 'credit', 'posting' => true],
            ['number' => '3000', 'name' => 'Equity', 'type' => 'equity', 'normal' => 'credit', 'posting' => true],
            ['number' => '4000', 'name' => 'Sales Revenue', 'type' => 'income', 'normal' => 'credit', 'posting' => true],
            ['number' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'cogs', 'normal' => 'debit', 'posting' => true],
            ['number' => '6000', 'name' => 'Operating Expenses', 'type' => 'expense', 'normal' => 'debit', 'posting' => true],
        ];
        foreach ($accounts as $account) {
            DB::table('accounting_accounts')->insert([
                'accounting_book_id' => $bookId,
                'number' => $account['number'],
                'name' => $account['name'],
                'account_type' => $account['type'],
                'normal_balance' => $account['normal'],
                'is_control' => false,
                'is_posting' => $account['posting'],
                'active' => true,
                'protected' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('accounting_periods')->insert([
            'accounting_book_id' => $bookId,
            'name' => (string) now()->year,
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_lines');
        Schema::dropIfExists('accounting_journals');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounting_accounts');
        Schema::dropIfExists('accounting_books');
    }
};
