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
            DB::statement("CREATE TABLE marketplace_return_removals (id INTEGER PRIMARY KEY AUTOINCREMENT, reference VARCHAR NOT NULL UNIQUE, marketplace_platform_id INTEGER NOT NULL, source_warehouse_id INTEGER NOT NULL, destination_warehouse_id INTEGER NOT NULL, status VARCHAR NOT NULL CHECK(status IN ('draft','requested','dispatched','received','cancelled')), external_removal_reference VARCHAR NULL, requested_at DATETIME NULL, dispatched_at DATETIME NULL, received_at DATETIME NULL, requested_by_user_id INTEGER NULL, dispatched_by_user_id INTEGER NULL, received_by_user_id INTEGER NULL, notes TEXT NULL, idempotency_key VARCHAR NOT NULL UNIQUE, dispatch_idempotency_key VARCHAR NULL UNIQUE, receive_idempotency_key VARCHAR NULL UNIQUE, created_at DATETIME NULL, updated_at DATETIME NULL, CHECK(source_warehouse_id != destination_warehouse_id), FOREIGN KEY(marketplace_platform_id) REFERENCES marketplace_platforms(id) ON DELETE RESTRICT, FOREIGN KEY(source_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT, FOREIGN KEY(destination_warehouse_id) REFERENCES warehouses(id) ON DELETE RESTRICT, FOREIGN KEY(requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY(dispatched_by_user_id) REFERENCES users(id) ON DELETE RESTRICT, FOREIGN KEY(received_by_user_id) REFERENCES users(id) ON DELETE RESTRICT)");
            DB::statement('CREATE INDEX marketplace_return_removals_status_index ON marketplace_return_removals(status)');

            return;
        }
        Schema::create('marketplace_return_removals', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('marketplace_platform_id')->constrained()->restrictOnDelete();
            $t->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('status')->index();
            $t->string('external_removal_reference')->nullable();
            $t->timestamp('requested_at')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('dispatched_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->uuid('idempotency_key')->unique();
            $t->uuid('dispatch_idempotency_key')->nullable()->unique();
            $t->uuid('receive_idempotency_key')->nullable()->unique();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE marketplace_return_removals ADD CONSTRAINT marketplace_removal_values_check CHECK(status IN ('draft','requested','dispatched','received','cancelled') AND source_warehouse_id <> destination_warehouse_id)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('marketplace_return_removals') && DB::table('marketplace_return_removals')->exists()) {
            throw new RuntimeException('Rollback refused: marketplace removal history exists.');
        }Schema::dropIfExists('marketplace_return_removals');
    }
};
