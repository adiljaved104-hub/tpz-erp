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
            $this->rebuildSqliteClaims(true);
            $this->createSqliteRefundsTable();

            return;
        }

        Schema::table('safet_claims', function (Blueprint $table): void {
            $table->decimal('claimed_amount', 15, 2)->nullable()->after('external_claim_reference');
            $table->decimal('approved_amount', 15, 2)->nullable()->after('claimed_amount');
            $table->decimal('reimbursed_amount', 15, 2)->nullable()->after('approved_amount');
            $table->char('currency', 3)->default('AED')->after('reimbursed_amount');
        });
        DB::statement('ALTER TABLE safet_claims ADD CONSTRAINT safet_claims_financial_amounts_check CHECK ((claimed_amount IS NULL OR claimed_amount >= 0) AND (approved_amount IS NULL OR approved_amount >= 0) AND (reimbursed_amount IS NULL OR reimbursed_amount >= 0))');

        Schema::create('customer_return_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_return_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('warranty_repair_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('return_type');
            $table->string('status');
            $table->decimal('refund_amount', 15, 2)->nullable();
            $table->char('currency', 3)->default('AED');
            $table->date('refund_date')->nullable();
            $table->string('external_refund_reference')->nullable();
            $table->text('note')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'refund_date']);
            $table->index('return_type');
        });
        DB::statement("ALTER TABLE customer_return_refunds ADD CONSTRAINT customer_return_refunds_values_check CHECK (return_type IN ('customer_return','warranty_refund','marketplace_refund','other') AND status IN ('refund_pending','refunded') AND (refund_amount IS NULL OR refund_amount > 0) AND (status != 'refunded' OR (refund_amount IS NOT NULL AND refund_date IS NOT NULL)))");
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_return_refunds') && DB::table('customer_return_refunds')->exists()) {
            throw new RuntimeException('Rollback refused: Return / Refund financial records exist.');
        }
        if (DB::table('safet_claims')
            ->whereNotNull('claimed_amount')
            ->orWhereNotNull('approved_amount')
            ->orWhereNotNull('reimbursed_amount')
            ->exists()) {
            throw new RuntimeException('Rollback refused: Claim financial outcomes exist.');
        }

        Schema::dropIfExists('customer_return_refunds');

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteClaims(false);

            return;
        }

        DB::statement('ALTER TABLE safet_claims DROP CHECK safet_claims_financial_amounts_check');
        Schema::table('safet_claims', function (Blueprint $table): void {
            $table->dropColumn(['claimed_amount', 'approved_amount', 'reimbursed_amount', 'currency']);
        });
    }

    private function createSqliteRefundsTable(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE customer_return_refunds (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 customer_return_id INTEGER NOT NULL UNIQUE,
 warranty_repair_id INTEGER NULL UNIQUE,
 return_type VARCHAR NOT NULL CHECK(return_type IN ('customer_return','warranty_refund','marketplace_refund','other')),
 status VARCHAR NOT NULL CHECK(status IN ('refund_pending','refunded')),
 refund_amount NUMERIC NULL CHECK(refund_amount IS NULL OR refund_amount > 0),
 currency VARCHAR(3) NOT NULL DEFAULT 'AED', refund_date DATE NULL,
 external_refund_reference VARCHAR NULL, note TEXT NULL,
 idempotency_key VARCHAR NOT NULL UNIQUE, recorded_by_user_id INTEGER NOT NULL,
 created_at DATETIME NULL, updated_at DATETIME NULL,
 CHECK(status != 'refunded' OR (refund_amount IS NOT NULL AND refund_date IS NOT NULL)),
 FOREIGN KEY(customer_return_id) REFERENCES customer_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY(warranty_repair_id) REFERENCES warranty_repairs(id) ON DELETE RESTRICT,
 FOREIGN KEY(recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
        DB::statement('CREATE INDEX customer_return_refunds_status_date_index ON customer_return_refunds(status,refund_date)');
        DB::statement('CREATE INDEX customer_return_refunds_return_type_index ON customer_return_refunds(return_type)');
    }

    private function rebuildSqliteClaims(bool $withFinancials): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            $financialColumns = $withFinancials
                ? ', claimed_amount NUMERIC NULL CHECK(claimed_amount IS NULL OR claimed_amount >= 0), approved_amount NUMERIC NULL CHECK(approved_amount IS NULL OR approved_amount >= 0), reimbursed_amount NUMERIC NULL CHECK(reimbursed_amount IS NULL OR reimbursed_amount >= 0), currency VARCHAR(3) NOT NULL DEFAULT \'AED\''
                : '';
            DB::statement("CREATE TABLE safet_claims_new (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE,
 marketplace_platform_id INTEGER NOT NULL, customer_return_id INTEGER NOT NULL,
 customer_return_item_id INTEGER NOT NULL, damaged_stock_event_id INTEGER NOT NULL UNIQUE,
 order_id INTEGER NOT NULL, order_item_id INTEGER NULL, order_fulfillment_item_id INTEGER NULL,
 product_id INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity > 0),
 source VARCHAR NOT NULL CHECK(source IN ('qc_damaged_customer_return')),
 status VARCHAR NOT NULL DEFAULT 'needs_filing' CHECK(status IN ('needs_filing','filed','in_review','approved','rejected','paid','closed','not_eligible')),
 claim_reason VARCHAR NOT NULL, claim_program_name VARCHAR NULL, external_claim_reference VARCHAR NULL{$financialColumns},
 assigned_to_user_id INTEGER NULL, filing_due_at DATETIME NULL, filed_at DATETIME NULL,
 reviewed_at DATETIME NULL, approved_at DATETIME NULL, rejected_at DATETIME NULL,
 paid_at DATETIME NULL, closed_at DATETIME NULL, notes TEXT NULL,
 idempotency_key VARCHAR NOT NULL UNIQUE, created_by_user_id INTEGER NULL,
 created_at DATETIME NULL, updated_at DATETIME NULL,
 FOREIGN KEY(marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_return_id) REFERENCES customer_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY(customer_return_item_id) REFERENCES customer_return_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(damaged_stock_event_id) REFERENCES damaged_stock_events(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(order_fulfillment_item_id) REFERENCES order_fulfillment_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
 FOREIGN KEY(assigned_to_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)");

            $baseColumns = 'id,reference,marketplace_platform_id,customer_return_id,customer_return_item_id,damaged_stock_event_id,order_id,order_item_id,order_fulfillment_item_id,product_id,quantity,source,status,claim_reason,claim_program_name,external_claim_reference';
            $tailColumns = 'assigned_to_user_id,filing_due_at,filed_at,reviewed_at,approved_at,rejected_at,paid_at,closed_at,notes,idempotency_key,created_by_user_id,created_at,updated_at';
            if ($withFinancials) {
                DB::statement("INSERT INTO safet_claims_new ({$baseColumns},claimed_amount,approved_amount,reimbursed_amount,currency,{$tailColumns}) SELECT {$baseColumns},NULL,NULL,NULL,'AED',{$tailColumns} FROM safet_claims");
            } else {
                DB::statement("INSERT INTO safet_claims_new ({$baseColumns},{$tailColumns}) SELECT {$baseColumns},{$tailColumns} FROM safet_claims");
            }
            DB::statement('DROP TABLE safet_claims');
            DB::statement('ALTER TABLE safet_claims_new RENAME TO safet_claims');
            DB::statement('CREATE INDEX safet_claims_status_due_index ON safet_claims(status,filing_due_at)');
            DB::statement('CREATE INDEX safet_claims_platform_status_index ON safet_claims(marketplace_platform_id,status)');
            DB::statement('CREATE INDEX safet_claims_product_status_index ON safet_claims(product_id,status)');
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
        if (DB::select('PRAGMA foreign_key_check') !== []) {
            throw new RuntimeException('Foreign-key violations detected after Claim financial schema rebuild.');
        }
    }
};
