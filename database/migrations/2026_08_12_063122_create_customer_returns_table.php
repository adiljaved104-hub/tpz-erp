<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
CREATE TABLE customer_returns (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE, order_id INTEGER NOT NULL,
 marketplace_platform_id INTEGER NULL, fulfillment_warehouse_id INTEGER NOT NULL,
 status VARCHAR NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','qc_pending','completed','cancelled')),
 return_source VARCHAR NOT NULL DEFAULT 'manual' CHECK (return_source IN ('manual','marketplace','api')),
 receiving_warehouse_id INTEGER NOT NULL, reported_at DATETIME NOT NULL, received_at DATETIME NULL,
 completed_at DATETIME NULL, cancelled_at DATETIME NULL, created_by_user_id INTEGER NOT NULL,
 received_by_user_id INTEGER NULL, cancellation_reason TEXT NULL, notes TEXT NULL,
 idempotency_key VARCHAR NOT NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL,
 FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE RESTRICT,
 FOREIGN KEY(marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT,
 FOREIGN KEY(fulfillment_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY(receiving_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT,
 FOREIGN KEY(created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
 FOREIGN KEY(received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX customer_returns_status_index ON customer_returns(status)');
            DB::statement('CREATE INDEX customer_returns_return_source_index ON customer_returns(return_source)');
            DB::statement('CREATE INDEX customer_returns_order_status_index ON customer_returns(order_id,status)');
            DB::statement('CREATE INDEX customer_returns_receiving_status_index ON customer_returns(receiving_warehouse_id,status)');

            return;
        }
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_platform_id')->nullable()->constrained('marketplace_platforms')->restrictOnDelete();
            $table->foreignId('fulfillment_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status')->default('draft')->index();
            $table->string('return_source')->default('manual')->index();
            $table->foreignId('receiving_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->timestamp('reported_at');
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['receiving_warehouse_id', 'status']);
        });

        DB::statement("ALTER TABLE customer_returns ADD CONSTRAINT customer_returns_values_check CHECK (status IN ('draft','qc_pending','completed','cancelled') AND return_source IN ('manual','marketplace','api'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_returns') && DB::table('customer_returns')->exists()) {
            throw new RuntimeException('Rollback refused: Customer Returns contain business records.');
        }
        Schema::dropIfExists('customer_returns');
    }
};
