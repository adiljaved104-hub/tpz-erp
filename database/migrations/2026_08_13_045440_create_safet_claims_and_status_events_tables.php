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
            DB::statement(<<<'SQL'
CREATE TABLE safet_claims (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE,
 marketplace_platform_id INTEGER NOT NULL, customer_return_id INTEGER NOT NULL,
 customer_return_item_id INTEGER NOT NULL, damaged_stock_event_id INTEGER NOT NULL UNIQUE,
 order_id INTEGER NOT NULL, order_item_id INTEGER NULL, order_fulfillment_item_id INTEGER NULL,
 product_id INTEGER NOT NULL, quantity INTEGER NOT NULL CHECK(quantity > 0),
 source VARCHAR NOT NULL CHECK(source IN ('qc_damaged_customer_return')),
 status VARCHAR NOT NULL DEFAULT 'needs_filing' CHECK(status IN ('needs_filing','filed','in_review','approved','rejected','paid','closed','not_eligible')),
 claim_reason VARCHAR NOT NULL, claim_program_name VARCHAR NULL, external_claim_reference VARCHAR NULL,
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
)
SQL);
            DB::statement('CREATE INDEX safet_claims_status_due_index ON safet_claims(status,filing_due_at)');
            DB::statement('CREATE INDEX safet_claims_platform_status_index ON safet_claims(marketplace_platform_id,status)');
            DB::statement('CREATE INDEX safet_claims_product_status_index ON safet_claims(product_id,status)');
            DB::statement(<<<'SQL'
CREATE TABLE safet_claim_status_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, safet_claim_id INTEGER NOT NULL,
 from_status VARCHAR NULL, to_status VARCHAR NOT NULL CHECK(to_status IN ('needs_filing','filed','in_review','approved','rejected','paid','closed','not_eligible')),
 reason TEXT NULL, changed_by_user_id INTEGER NULL, changed_at DATETIME NOT NULL,
 created_at DATETIME NULL, updated_at DATETIME NULL,
 FOREIGN KEY(safet_claim_id) REFERENCES safet_claims(id) ON DELETE RESTRICT,
 FOREIGN KEY(changed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX safet_claim_status_events_claim_changed_index ON safet_claim_status_events(safet_claim_id,changed_at)');

            return;
        }

        Schema::create('safet_claims', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('marketplace_platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_return_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('damaged_stock_event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_fulfillment_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('source');
            $table->string('status')->default('needs_filing');
            $table->string('claim_reason');
            $table->string('claim_program_name')->nullable();
            $table->string('external_claim_reference')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->restrictOnDelete();
            foreach (['filing_due_at', 'filed_at', 'reviewed_at', 'approved_at', 'rejected_at', 'paid_at', 'closed_at'] as $column) {
                $table->timestamp($column)->nullable();
            }
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'filing_due_at']);
            $table->index(['marketplace_platform_id', 'status']);
            $table->index(['product_id', 'status']);
        });
        DB::statement("ALTER TABLE safet_claims ADD CONSTRAINT safet_claims_values_check CHECK (quantity > 0 AND source IN ('qc_damaged_customer_return') AND status IN ('needs_filing','filed','in_review','approved','rejected','paid','closed','not_eligible'))");
        Schema::create('safet_claim_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('safet_claim_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['safet_claim_id', 'changed_at']);
        });
        DB::statement("ALTER TABLE safet_claim_status_events ADD CONSTRAINT safet_claim_status_events_status_check CHECK (to_status IN ('needs_filing','filed','in_review','approved','rejected','paid','closed','not_eligible'))");
    }

    public function down(): void
    {
        if (Schema::hasTable('safet_claims') && DB::table('safet_claims')->exists()) {
            throw new RuntimeException('Rollback refused: Claims history exists.');
        }
        Schema::dropIfExists('safet_claim_status_events');
        Schema::dropIfExists('safet_claims');
    }
};
