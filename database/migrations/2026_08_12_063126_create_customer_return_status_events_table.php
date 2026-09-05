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
CREATE TABLE customer_return_status_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, customer_return_id INTEGER NOT NULL,
 from_status VARCHAR NULL CHECK(from_status IS NULL OR from_status IN ('draft','qc_pending','completed','cancelled')),
 to_status VARCHAR NOT NULL CHECK(to_status IN ('draft','qc_pending','completed','cancelled')),
 actor_user_id INTEGER NOT NULL, reason TEXT NULL, context TEXT NULL, created_at DATETIME NOT NULL,
 FOREIGN KEY(customer_return_id) REFERENCES customer_returns(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
)
SQL);
            DB::statement('CREATE INDEX customer_return_status_events_return_created_index ON customer_return_status_events(customer_return_id,created_at)');

            return;
        }
        Schema::create('customer_return_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_return_id')->constrained()->restrictOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at');
            $table->index(['customer_return_id', 'created_at'], 'customer_return_status_events_return_created_index');
        });
        DB::statement("ALTER TABLE customer_return_status_events ADD CONSTRAINT customer_return_status_events_values_check CHECK ((from_status IS NULL OR from_status IN ('draft','qc_pending','completed','cancelled')) AND to_status IN ('draft','qc_pending','completed','cancelled'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('customer_return_status_events') && DB::table('customer_return_status_events')->exists()) {
            throw new RuntimeException('Rollback refused: Customer Return status history exists.');
        }
        Schema::dropIfExists('customer_return_status_events');
    }
};
